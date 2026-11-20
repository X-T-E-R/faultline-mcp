<?php

declare(strict_types=1);

namespace Faultline\Agent;

use Faultline\Chaos\ChaosEngine;
use Faultline\Chaos\ChaosScenario;
use Faultline\Chaos\ChaosScenarioCatalog;
use Faultline\FlightRecorder\FlightRecorder;
use Faultline\Guard\FaultlineGuardedToolset;
use Faultline\Ledger\LedgerDiffCalculator;
use Faultline\Ledger\LedgerStore;
use Faultline\Proxy\BidirectionalChaosProxy;
use Faultline\Proxy\InProcessPsr18TransportBridge;
use Faultline\TargetServer\CommerceServerFactory;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Extension\Apps\McpApps;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Executes a head-to-head deterministic Chaos Duel between:
 *  - Track A (`unguarded`): Stock Symfony AI `Agent` + `FaultTolerantToolbox` + `McpToolbox` + `ClientToolset`
 *  - Track B (`guarded`):   Symfony AI `Agent` + `FaultTolerantToolbox` + `McpToolbox` + `FaultlineGuardedToolset`
 *
 * Both tracks run against identical initial SQLite ledgers and identical PRNG fault seeds over real
 * `Mcp\Client\Transport\HttpTransport` <-> `BidirectionalChaosProxy` <-> `Mcp\Server\Transport\StreamableHttpTransport`.
 */
final class DuelRunner
{
    private readonly FlightRecorder $recorder;
    private readonly BidirectionalChaosProxy $proxy;
    private readonly Psr17Factory $psr17;

    public function __construct(
        private readonly LedgerStore $ledger,
    ) {
        $this->recorder = new FlightRecorder($this->ledger->getPdo());
        $this->proxy = new BidirectionalChaosProxy(new ChaosEngine($this->recorder), $this->recorder);
        $this->psr17 = new Psr17Factory();
    }

    public function getRecorder(): FlightRecorder
    {
        return $this->recorder;
    }

    public function getProxy(): BidirectionalChaosProxy
    {
        return $this->proxy;
    }

    /**
     * @return array<string, mixed>
     */
    public function runDuel(
        string $scenarioId,
        int $seed = 20261123,
        bool $preferLiveHf = false,
        ?string $duelId = null,
    ): array {
        $scenario = ChaosScenarioCatalog::get($scenarioId);
        $baseId = $duelId ?? \sprintf('duel_%s_%d_%s', $scenario->id, $seed, substr(bin2hex(random_bytes(3)), 0, 6));

        $unguardedResult = $this->executeSingleTrack(
            runId: $baseId.'_unguarded',
            scope: 'unguarded_'.$baseId,
            track: 'unguarded',
            scenario: $scenario,
            seed: $seed,
            guarded: false,
            preferLiveHf: $preferLiveHf,
        );

        $guardedResult = $this->executeSingleTrack(
            runId: $baseId.'_guarded',
            scope: 'guarded_'.$baseId,
            track: 'guarded',
            scenario: $scenario,
            seed: $seed,
            guarded: true,
            preferLiveHf: $preferLiveHf,
        );

        $comparison = LedgerDiffCalculator::compareTracks(
            $unguardedResult['evaluation'],
            $guardedResult['evaluation'],
            $unguardedResult['ledger_after'],
            $guardedResult['ledger_after'],
        );

        return [
            'duel_id' => $baseId,
            'seed' => $seed,
            'scenario' => $scenario->toArray(),
            'engine_mode' => $guardedResult['engine_mode'],
            'model' => $guardedResult['model'],
            'unguarded' => $unguardedResult,
            'guarded' => $guardedResult,
            'comparison' => $comparison,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSingleTrack(
        string $runId,
        string $scope,
        string $track,
        ChaosScenario $scenario,
        int $seed,
        bool $guarded,
        bool $preferLiveHf,
    ): array {
        $startedAt = microtime(true);
        $this->ledger->resetScope($scope);
        $ledgerBefore = $this->ledger->snapshot($scope);

        $this->recorder->startRun($runId, $scenario->id, $seed, $track);

        $upstreamServer = CommerceServerFactory::createServer(
            ledger: $this->ledger,
            scope: $scope,
            sessionStorageDir: null,
            paginationLimit: $scenario->paginationLimit,
        );

        $bridge = new InProcessPsr18TransportBridge(
            proxy: $this->proxy,
            upstreamServer: $upstreamServer,
            runId: $runId,
            seed: $seed,
            scenario: $scenario,
            track: $track,
            psr17: $this->psr17,
        );

        $httpTransport = new HttpTransport(
            endpoint: 'http://faultline.local/mcp/proxy',
            httpClient: $bridge,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
        );

        $mcpClient = Client::builder()
            ->setClientInfo('symfony-ai-'.$track, '1.0.0')
            ->enableExtension(new McpApps())
            ->setRequestTimeout(15)
            ->build();

        $toolset = $guarded
            ? new FaultlineGuardedToolset(
                name: 'commerce',
                client: $mcpClient,
                transport: $httpTransport,
                recorder: $this->recorder,
                runId: $runId,
                transportBridge: $bridge,
            )
            : new ClientToolset(
                name: 'commerce',
                client: $mcpClient,
                transport: $httpTransport,
            );

        $mcpToolbox = new McpToolbox($toolset, prefix: 'commerce_');
        $exposedTools = $mcpToolbox->getTools();
        $exposedToolCount = \count($exposedTools);

        $faultTolerantToolbox = new FaultTolerantToolbox($mcpToolbox);
        $platformSpec = AgentPlatformFactory::createForScenario(
            scenario: $scenario,
            toolPrefix: 'commerce_',
            preferLiveHf: $preferLiveHf,
        );

        $agent = new Agent(
            platform: $platformSpec['platform'],
            model: $platformSpec['model'],
            name: 'commerce_'.$track.'_agent',
            toolbox: $faultTolerantToolbox,
            maxToolCalls: 12,
        );

        $messages = new MessageBag(
            Message::forSystem(
                'You are an autonomous e-commerce settlement agent. Always inspect the order first with '
                .'commerce_get_order, then execute the requested settlement action exactly once.'
            ),
            Message::ofUser($scenario->agentPrompt),
        );

        $agentOutput = '';
        $agentStatus = 'completed';
        try {
            $execution = $agent->call($messages);
            $content = $execution->getContent();
            $agentOutput = \is_string($content) ? $content : (json_encode($content, \JSON_UNESCAPED_SLASHES) ?: '');
        } catch (\Throwable $e) {
            $agentStatus = 'failed';
            $agentOutput = 'Agent terminated with exception: '.$e->getMessage();
        } finally {
            $toolset->disconnect();
        }

        $ledgerAfter = $this->ledger->snapshot($scope);
        $evaluation = LedgerDiffCalculator::evaluateRun(
            before: $ledgerBefore,
            after: $ledgerAfter,
            expectedDeltaCents: $scenario->expectedLedgerDeltaCents,
            expectedWriteCount: $scenario->expectedWriteCount,
            agentOutput: $agentOutput,
            exposedToolCount: $exposedToolCount,
        );

        $frames = $this->recorder->getFrames($runId);
        $resilienceScore = self::computeResilienceScore($evaluation, $frames, $exposedToolCount);
        $wallMs = round((microtime(true) - $startedAt) * 1000, 2);

        $summary = [
            'verdict' => $evaluation['verdict'],
            'integrity_passed' => $evaluation['integrity_passed'],
            'resilience_score' => $resilienceScore,
            'drift_cents' => $evaluation['drift_cents'],
            'write_count' => $evaluation['write_count'],
            'frame_count' => \count($frames),
            'exposed_tool_count' => $exposedToolCount,
            'wall_ms' => $wallMs,
        ];
        $this->recorder->finishRun($runId, $agentStatus, $summary);

        return [
            'run_id' => $runId,
            'track' => $track,
            'status' => $agentStatus,
            'engine_mode' => $platformSpec['mode'],
            'model' => $platformSpec['model'],
            'agent_output' => $agentOutput,
            'resilience_score' => $resilienceScore,
            'exposed_tool_count' => $exposedToolCount,
            'wall_ms' => $wallMs,
            'evaluation' => $evaluation,
            'ledger_before' => $ledgerBefore,
            'ledger_after' => $ledgerAfter,
            'frames' => $frames,
        ];
    }

    /**
     * @param array<string, mixed>       $evaluation
     * @param list<array<string, mixed>> $frames
     */
    private static function computeResilienceScore(array $evaluation, array $frames, int $exposedToolCount): int
    {
        $score = 100;

        if (true !== ($evaluation['integrity_passed'] ?? false)) {
            $score -= 65;
        }
        $duplicates = (int) ($evaluation['duplicate_writes'] ?? 0);
        if ($duplicates > 0) {
            $score -= min(25, $duplicates * 20);
        }

        // Penalize unguarded cyclic cursor storms (> 5 unique tools means duplicates leaked into toolbox!)
        if ($exposedToolCount > 5) {
            $score -= min(35, ($exposedToolCount - 5) * 7);
        }

        $unhandledFaults = 0;
        foreach ($frames as $frame) {
            if (null !== ($frame['fault_injected'] ?? null) && null === ($frame['guard_action'] ?? null)) {
                ++$unhandledFaults;
            }
        }
        if (true !== ($evaluation['integrity_passed'] ?? false) && $unhandledFaults > 0) {
            $score -= min(15, $unhandledFaults * 5);
        }

        return max(0, min(100, $score));
    }
}
