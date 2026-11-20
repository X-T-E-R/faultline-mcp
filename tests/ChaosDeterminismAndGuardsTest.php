<?php

declare(strict_types=1);

namespace Faultline\Tests;

use Faultline\Agent\DuelRunner;
use Faultline\Chaos\SeededPrng;
use Faultline\Ledger\LedgerStore;
use PHPUnit\Framework\TestCase;

final class ChaosDeterminismAndGuardsTest extends TestCase
{
    public function testSeededPrngProducesBitIdenticalSchedulesAcrossRuns(): void
    {
        $a = new SeededPrng(20261123);
        $b = new SeededPrng(20261123);
        $c = new SeededPrng(99999999);

        $seqA = [$a->nextUint32('rpc'), $a->nextIntBetween(10, 500, 'lat'), $a->nextFloat('p')];
        $seqB = [$b->nextUint32('rpc'), $b->nextIntBetween(10, 500, 'lat'), $b->nextFloat('p')];
        $seqC = [$c->nextUint32('rpc'), $c->nextIntBetween(10, 500, 'lat'), $c->nextFloat('p')];

        self::assertSame($seqA, $seqB);
        self::assertNotSame($seqA, $seqC);

        // Key-order invariant canonical SHA-256
        $hash1 = SeededPrng::canonicalSha256(['b' => 2, 'a' => ['y' => 9, 'x' => 1]]);
        $hash2 = SeededPrng::canonicalSha256(['a' => ['x' => 1, 'y' => 9], 'b' => 2]);
        self::assertSame($hash1, $hash2);
    }

    public function testPostCommitTruncationCatchesDoubleRefundAndGuardedReplaysSafely(): void
    {
        $runner = new DuelRunner(new LedgerStore(':memory:'));
        $duel = $runner->runDuel('post_commit_truncation', 20261123);

        $uEval = $duel['unguarded']['evaluation'];
        $gEval = $duel['guarded']['evaluation'];

        self::assertFalse($uEval['integrity_passed']);
        self::assertSame('DOUBLE_REFUND_DETECTED', $uEval['verdict']);
        self::assertSame(-29800, $uEval['actual_delta_cents']);
        self::assertSame(2, $uEval['write_count']);

        self::assertTrue($gEval['integrity_passed']);
        self::assertSame('INVARIANT_PRESERVED', $gEval['verdict']);
        self::assertSame(-14900, $gEval['actual_delta_cents']);
        self::assertSame(1, $gEval['write_count']);
        self::assertSame(100, $duel['guarded']['resilience_score']);
        self::assertSame(14900, $duel['comparison']['capital_protected_cents']);

        $guardActions = array_values(array_filter(array_map(
            static fn (array $f): ?string => $f['guard_action'] ?? null,
            $duel['guarded']['frames'],
        )));
        self::assertContains('SEALED_COMMITTED_INTENT', $guardActions);
        self::assertContains('SAFE_IDEMPOTENT_REPLAY', $guardActions);
    }

    public function testHttp429And502StormCatchesDoubleChargeAndRecoversRetryAfter(): void
    {
        $runner = new DuelRunner(new LedgerStore(':memory:'));
        $duel = $runner->runDuel('http_429_storm', 20261123);

        $uEval = $duel['unguarded']['evaluation'];
        $gEval = $duel['guarded']['evaluation'];

        self::assertSame('DOUBLE_CHARGE_DETECTED', $uEval['verdict']);
        self::assertSame(17000, $uEval['actual_delta_cents']);
        self::assertSame(2, $uEval['write_count']);

        self::assertTrue($gEval['integrity_passed']);
        self::assertSame('INVARIANT_PRESERVED', $gEval['verdict']);
        self::assertSame(8500, $gEval['actual_delta_cents']);
        self::assertSame(1, $gEval['write_count']);

        $guardActions = array_values(array_filter(array_map(
            static fn (array $f): ?string => $f['guard_action'] ?? null,
            $duel['guarded']['frames'],
        )));
        self::assertContains('RETRY_AFTER_RECOVERED', $guardActions);
        self::assertContains('SAFE_IDEMPOTENT_REPLAY', $guardActions);
    }

    public function testSustained429BurstCatchesHallucinatedToolSuccessOnZeroWrites(): void
    {
        $runner = new DuelRunner(new LedgerStore(':memory:'));
        $duel = $runner->runDuel('retry_hallucination', 20261123);

        $uEval = $duel['unguarded']['evaluation'];
        $gEval = $duel['guarded']['evaluation'];

        self::assertFalse($uEval['integrity_passed']);
        self::assertSame('HALLUCINATED_TOOL_SUCCESS', $uEval['verdict']);
        self::assertSame(0, $uEval['write_count']);
        self::assertStringContainsString('processed', $duel['unguarded']['agent_output']);

        self::assertTrue($gEval['integrity_passed']);
        self::assertSame('INVARIANT_PRESERVED', $gEval['verdict']);
        self::assertSame(-14900, $gEval['actual_delta_cents']);
        self::assertSame(1, $gEval['write_count']);
    }

    public function testMidSessionSchemaDriftCatches100xUnitScaleCatastrophe(): void
    {
        $runner = new DuelRunner(new LedgerStore(':memory:'));
        $duel = $runner->runDuel('schema_drift', 20261123);

        $uEval = $duel['unguarded']['evaluation'];
        $gEval = $duel['guarded']['evaluation'];

        self::assertFalse($uEval['integrity_passed']);
        self::assertSame('UNIT_SCALE_DRIFT_CATASTROPHE', $uEval['verdict']);
        self::assertSame(-1490000, $uEval['actual_delta_cents']);

        self::assertTrue($gEval['integrity_passed']);
        self::assertSame('INVARIANT_PRESERVED', $gEval['verdict']);
        self::assertSame(-14900, $gEval['actual_delta_cents']);

        $guardActions = array_values(array_filter(array_map(
            static fn (array $f): ?string => $f['guard_action'] ?? null,
            $duel['guarded']['frames'],
        )));
        self::assertContains('SCHEMA_DRIFT_CAUGHT_AND_PINNED', $guardActions);
    }

    public function testCursorTamperExposesClientToolsetLoopAndGuardedCycleBreaker(): void
    {
        $runner = new DuelRunner(new LedgerStore(':memory:'));
        $duel = $runner->runDuel('cursor_tamper', 20261123);

        self::assertSame('CURSOR_LOOP_TOOL_INFLATION', $duel['unguarded']['evaluation']['verdict']);
        self::assertGreaterThan(10, $duel['unguarded']['exposed_tool_count']);

        self::assertTrue($duel['guarded']['evaluation']['integrity_passed']);
        self::assertLessThanOrEqual(5, $duel['guarded']['exposed_tool_count']);

        $guardActions = array_values(array_filter(array_map(
            static fn (array $f): ?string => $f['guard_action'] ?? null,
            $duel['guarded']['frames'],
        )));
        self::assertContains('CURSOR_CYCLE_BROKEN', $guardActions);
    }

    public function testReplayReproducibilityAcrossSeparateRunnerInstances(): void
    {
        $duel1 = (new DuelRunner(new LedgerStore(':memory:')))->runDuel('gauntlet_all', 20261123, false, 'rep_1');
        $duel2 = (new DuelRunner(new LedgerStore(':memory:')))->runDuel('gauntlet_all', 20261123, false, 'rep_2');

        $latencies1 = array_map(static fn (array $f): float => $f['latency_ms'], $duel1['unguarded']['frames']);
        $latencies2 = array_map(static fn (array $f): float => $f['latency_ms'], $duel2['unguarded']['frames']);
        $faults1 = array_map(static fn (array $f): ?string => $f['fault_injected'], $duel1['unguarded']['frames']);
        $faults2 = array_map(static fn (array $f): ?string => $f['fault_injected'], $duel2['unguarded']['frames']);

        self::assertSame($faults1, $faults2);
        self::assertSame($latencies1, $latencies2);
        self::assertSame($duel1['unguarded']['evaluation'], $duel2['unguarded']['evaluation']);
        self::assertSame($duel1['guarded']['evaluation'], $duel2['guarded']['evaluation']);

        // Re-running with the exact same duelId on the same SQLite database must reset run state cleanly
        $sharedRunner = new DuelRunner(new LedgerStore(':memory:'));
        $firstSameId = $sharedRunner->runDuel('post_commit_truncation', 20261123, false, 'fixed_boot_id');
        $secondSameId = $sharedRunner->runDuel('post_commit_truncation', 20261123, false, 'fixed_boot_id');
        self::assertSame('DOUBLE_REFUND_DETECTED', $firstSameId['unguarded']['evaluation']['verdict']);
        self::assertSame('DOUBLE_REFUND_DETECTED', $secondSameId['unguarded']['evaluation']['verdict']);
        self::assertSame('INVARIANT_PRESERVED', $secondSameId['guarded']['evaluation']['verdict']);
        self::assertCount(\count($firstSameId['unguarded']['frames']), $secondSameId['unguarded']['frames']);
    }
}
