<?php

declare(strict_types=1);

namespace Faultline\FlightRecorder;

/**
 * Immutable SQLite Flight Recorder storing JSON-RPC wire frames, fault injections,
 * deterministic occurrence counters, and sealed idempotency receipts.
 */
final class FlightRecorder
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->initializeSchema();
    }

    public function startRun(
        string $runId,
        string $scenarioId,
        int $seed,
        string $track,
    ): void {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM chaos_counters WHERE run_id = :run_id')->execute(['run_id' => $runId]);
            $this->pdo->prepare('DELETE FROM wire_frames WHERE run_id = :run_id')->execute(['run_id' => $runId]);
            $this->pdo->prepare('DELETE FROM idempotency_seals WHERE run_id = :run_id')->execute(['run_id' => $runId]);

            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO chaos_runs (run_id, scenario_id, seed, track, status, summary_json, started_at_us, finished_at_us)
                 VALUES (:run_id, :scenario_id, :seed, :track, :status, :summary_json, :started_at_us, NULL)'
            );
            $stmt->execute([
                'run_id' => $runId,
                'scenario_id' => $scenarioId,
                'seed' => $seed,
                'track' => $track,
                'status' => 'running',
                'summary_json' => '{}',
                'started_at_us' => (int) (microtime(true) * 1_000_000),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function finishRun(string $runId, string $status, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chaos_runs SET status = :status, summary_json = :summary_json, finished_at_us = :finished_at_us WHERE run_id = :run_id'
        );
        $encoded = json_encode($summary, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $stmt->execute([
            'status' => $status,
            'summary_json' => false !== $encoded ? $encoded : '{}',
            'finished_at_us' => (int) (microtime(true) * 1_000_000),
            'run_id' => $runId,
        ]);
    }

    /**
     * Atomically increments and returns the 1-based occurrence counter for `(runId, counterKey)`.
     */
    public function nextOccurrence(string $runId, string $counterKey): int
    {
        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->prepare(
                'SELECT count_val FROM chaos_counters WHERE run_id = :run_id AND counter_key = :counter_key'
            );
            $sel->execute(['run_id' => $runId, 'counter_key' => $counterKey]);
            $current = $sel->fetchColumn();

            $next = (false === $current ? 0 : (int) $current) + 1;

            $ups = $this->pdo->prepare(
                'INSERT INTO chaos_counters (run_id, counter_key, count_val) VALUES (:run_id, :counter_key, :count_val)
                 ON CONFLICT(run_id, counter_key) DO UPDATE SET count_val = excluded.count_val'
            );
            $ups->execute([
                'run_id' => $runId,
                'counter_key' => $counterKey,
                'count_val' => $next,
            ]);

            $this->pdo->commit();

            return $next;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCounter(string $runId, string $counterKey): int
    {
        $sel = $this->pdo->prepare(
            'SELECT count_val FROM chaos_counters WHERE run_id = :run_id AND counter_key = :counter_key'
        );
        $sel->execute(['run_id' => $runId, 'counter_key' => $counterKey]);
        $current = $sel->fetchColumn();

        return false === $current ? 0 : (int) $current;
    }

    /**
     * Records one JSON-RPC / HTTP transport wire frame in the flight log.
     *
     * @param array<string, mixed> $meta
     */
    public function recordFrame(
        string $runId,
        string $track,
        string $direction,
        string $rpcMethod,
        ?string $toolName,
        int $httpStatus,
        float $latencyMs,
        ?string $faultInjected,
        ?string $guardAction,
        string $rawRequest,
        string $rawResponse,
        array $meta = [],
    ): int {
        $seq = $this->nextOccurrence($runId, '__frame_seq__');
        $metaJson = json_encode($meta, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare(
            'INSERT INTO wire_frames (
                run_id, seq, track, direction, rpc_method, tool_name, http_status,
                latency_ms, fault_injected, guard_action, request_payload, response_payload,
                meta_json, recorded_at_us
            ) VALUES (
                :run_id, :seq, :track, :direction, :rpc_method, :tool_name, :http_status,
                :latency_ms, :fault_injected, :guard_action, :request_payload, :response_payload,
                :meta_json, :recorded_at_us
            )'
        );
        $stmt->execute([
            'run_id' => $runId,
            'seq' => $seq,
            'track' => $track,
            'direction' => $direction,
            'rpc_method' => $rpcMethod,
            'tool_name' => $toolName,
            'http_status' => $httpStatus,
            'latency_ms' => round($latencyMs, 2),
            'fault_injected' => $faultInjected,
            'guard_action' => $guardAction,
            'request_payload' => $rawRequest,
            'response_payload' => $rawResponse,
            'meta_json' => false !== $metaJson ? $metaJson : '{}',
            'recorded_at_us' => (int) (microtime(true) * 1_000_000),
        ]);

        return $seq;
    }

    /**
     * Seals an upstream tool response under a deterministic idempotency key so retries after network/truncation
     * faults replay the exact original outcome without re-invoking the mutating tool.
     */
    public function sealIdempotentResult(
        string $runId,
        string $idempotencyKey,
        string $toolName,
        string $argsSha256,
        string $schemaSha256,
        string $resultJson,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO idempotency_seals (
                run_id, idempotency_key, tool_name, args_sha256, schema_sha256, result_json, replay_count, created_at_us
            ) VALUES (
                :run_id, :idempotency_key, :tool_name, :args_sha256, :schema_sha256, :result_json, 0, :created_at_us
            )'
        );
        $stmt->execute([
            'run_id' => $runId,
            'idempotency_key' => $idempotencyKey,
            'tool_name' => $toolName,
            'args_sha256' => $argsSha256,
            'schema_sha256' => $schemaSha256,
            'result_json' => $resultJson,
            'created_at_us' => (int) (microtime(true) * 1_000_000),
        ]);
    }

    /**
     * Looks up a previously committed idempotent seal and increments `replay_count` when matched.
     *
     * @return array{
     *     idempotency_key: string,
     *     tool_name: string,
     *     args_sha256: string,
     *     schema_sha256: string,
     *     result_json: string,
     *     replay_count: int
     * }|null
     */
    public function consumeSealedResult(string $runId, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM idempotency_seals WHERE run_id = :run_id AND idempotency_key = :key LIMIT 1'
        );
        $stmt->execute(['run_id' => $runId, 'key' => $idempotencyKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }

        $replayCount = (int) $row['replay_count'] + 1;
        $upd = $this->pdo->prepare(
            'UPDATE idempotency_seals SET replay_count = :replays WHERE run_id = :run_id AND idempotency_key = :key'
        );
        $upd->execute([
            'replays' => $replayCount,
            'run_id' => $runId,
            'key' => $idempotencyKey,
        ]);

        return [
            'idempotency_key' => (string) $row['idempotency_key'],
            'tool_name' => (string) $row['tool_name'],
            'args_sha256' => (string) $row['args_sha256'],
            'schema_sha256' => (string) $row['schema_sha256'],
            'result_json' => (string) $row['result_json'],
            'replay_count' => $replayCount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFrames(string $runId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wire_frames WHERE run_id = :run_id ORDER BY seq ASC');
        $stmt->execute(['run_id' => $runId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $meta = json_decode((string) $row['meta_json'], true);

            return [
                'seq' => (int) $row['seq'],
                'run_id' => (string) $row['run_id'],
                'track' => (string) $row['track'],
                'direction' => (string) $row['direction'],
                'rpc_method' => (string) $row['rpc_method'],
                'tool_name' => $row['tool_name'],
                'http_status' => (int) $row['http_status'],
                'latency_ms' => (float) $row['latency_ms'],
                'fault_injected' => $row['fault_injected'],
                'guard_action' => $row['guard_action'],
                'request_payload' => (string) $row['request_payload'],
                'response_payload' => (string) $row['response_payload'],
                'meta' => \is_array($meta) ? $meta : [],
                'recorded_at_us' => (int) $row['recorded_at_us'],
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecentRuns(int $limit = 12): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chaos_runs ORDER BY started_at_us DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $summary = json_decode((string) $row['summary_json'], true);

            return [
                'run_id' => (string) $row['run_id'],
                'scenario_id' => (string) $row['scenario_id'],
                'seed' => (int) $row['seed'],
                'track' => (string) $row['track'],
                'status' => (string) $row['status'],
                'summary' => \is_array($summary) ? $summary : [],
                'started_at_us' => (int) $row['started_at_us'],
                'finished_at_us' => null !== $row['finished_at_us'] ? (int) $row['finished_at_us'] : null,
            ];
        }, $rows);
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS chaos_runs (
                run_id TEXT PRIMARY KEY,
                scenario_id TEXT NOT NULL,
                seed INTEGER NOT NULL,
                track TEXT NOT NULL,
                status TEXT NOT NULL,
                summary_json TEXT NOT NULL,
                started_at_us INTEGER NOT NULL,
                finished_at_us INTEGER DEFAULT NULL
            );

            CREATE TABLE IF NOT EXISTS chaos_counters (
                run_id TEXT NOT NULL,
                counter_key TEXT NOT NULL,
                count_val INTEGER NOT NULL,
                PRIMARY KEY (run_id, counter_key)
            );

            CREATE TABLE IF NOT EXISTS wire_frames (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                seq INTEGER NOT NULL,
                track TEXT NOT NULL,
                direction TEXT NOT NULL,
                rpc_method TEXT NOT NULL,
                tool_name TEXT DEFAULT NULL,
                http_status INTEGER NOT NULL,
                latency_ms REAL NOT NULL,
                fault_injected TEXT DEFAULT NULL,
                guard_action TEXT DEFAULT NULL,
                request_payload TEXT NOT NULL,
                response_payload TEXT NOT NULL,
                meta_json TEXT NOT NULL,
                recorded_at_us INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_wire_frames_run ON wire_frames (run_id, seq);

            CREATE TABLE IF NOT EXISTS idempotency_seals (
                run_id TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                tool_name TEXT NOT NULL,
                args_sha256 TEXT NOT NULL,
                schema_sha256 TEXT NOT NULL,
                result_json TEXT NOT NULL,
                replay_count INTEGER NOT NULL DEFAULT 0,
                created_at_us INTEGER NOT NULL,
                PRIMARY KEY (run_id, idempotency_key)
            );
            SQL
        );
    }
}
