<?php

declare(strict_types=1);

namespace Faultline\Chaos;

/**
 * Curated deterministic chaos scenarios targeting real failure modes in PHP MCP SDK & Symfony AI agents.
 */
final class ChaosScenarioCatalog
{
    /**
     * @return array<string, ChaosScenario>
     */
    public static function all(): array
    {
        $scenarios = [
            new ChaosScenario(
                id: 'post_commit_truncation',
                title: 'Post-Commit Truncated JSON-RPC Frame',
                category: 'TRUNCATED_WIRE_DROP',
                summary: 'Upstream commits €149.00 refund to SQLite, then proxy severs outbound JSON-RPC at byte 42. Unguarded agent retries and double-refunds €298.00.',
                targetOrderId: 'ORD-9042',
                targetTool: 'issue_refund',
                intendedAmountCents: 14900,
                expectedLedgerDeltaCents: -14900,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect order ORD-9042 for Clara Oswald and issue a full refund of 14900 cents (reason: damaged_chrono_dial).',
                faultConfig: [
                    'truncate_post_commit_occurrences' => [1],
                    'truncate_keep_bytes' => 42,
                    'jitter_base_ms' => 18,
                    'spike_latency_ms' => 145,
                ],
            ),
            new ChaosScenario(
                id: 'http_429_storm',
                title: 'HTTP 429 Retry-After + Post-Commit 502 Storm',
                category: 'RATE_LIMIT_RETRY_STORM',
                summary: 'Injects HTTP 429 (Retry-After: 1) on attempt #1 and post-commit HTTP 502 on attempt #2 during €85.00 payment capture. Exposes Retry-After loss & double-charge.',
                targetOrderId: 'ORD-9043',
                targetTool: 'capture_payment',
                intendedAmountCents: 8500,
                expectedLedgerDeltaCents: 8500,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect authorized order ORD-9043 for Marc Weber and capture the full 8500 cents payment (memo: dispatch_brass_caliper).',
                faultConfig: [
                    'pre_commit_429_occurrences' => [1],
                    'retry_after_seconds' => 1,
                    'post_commit_502_occurrences' => [2],
                    'jitter_base_ms' => 14,
                    'spike_latency_ms' => 110,
                ],
            ),
            new ChaosScenario(
                id: 'retry_hallucination',
                title: '429 Burst Exhaustion & Hallucinated Success',
                category: 'HALLUCINATED_SETTLEMENT',
                summary: 'Sustained HTTP 429 window rejects unpaced 0ms retries until the unguarded agent hallucinates "Refund processed" with 0 SQLite writes; Faultline honors Retry-After and verifies receipt.',
                targetOrderId: 'ORD-9042',
                targetTool: 'issue_refund',
                intendedAmountCents: 14900,
                expectedLedgerDeltaCents: -14900,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect order ORD-9042 for Clara Oswald and process her 14900 cents refund under burst load.',
                faultConfig: [
                    'pre_commit_429_occurrences' => [1],
                    'require_backoff_after_429' => true,
                    'retry_after_seconds' => 1,
                    'simulate_optimistic_hallucination' => true,
                    'jitter_base_ms' => 15,
                    'spike_latency_ms' => 125,
                ],
            ),
            new ChaosScenario(
                id: 'schema_drift',
                title: 'Mid-Session Tool JSON Schema Drift (100x Scale)',
                category: 'SCHEMA_DRIFT_MUTATION',
                summary: 'After initial tools/list, upstream mutates issue_refund from integer cents to major EUR units (100x scale). Stale cached schema causes €14,900.00 over-refund.',
                targetOrderId: 'ORD-9042',
                targetTool: 'issue_refund',
                intendedAmountCents: 14900,
                expectedLedgerDeltaCents: -14900,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect order ORD-9042 and issue a full refund of 14900 cents (reason: calibration_return).',
                faultConfig: [
                    'enable_mid_session_schema_drift' => true,
                    'drift_unit_multiplier' => 100,
                    'jitter_base_ms' => 12,
                    'spike_latency_ms' => 65,
                ],
            ),
            new ChaosScenario(
                id: 'cursor_tamper',
                title: 'Pagination Cursor Cycle & Stateless Token Tamper',
                category: 'CURSOR_CYCLE_POISON',
                summary: 'Upstream tools/list (limit=2) returns a cyclic nextCursor pointing back to page 1. Triggers unbounded loop in ClientToolset::getTools() without cycle breaker.',
                targetOrderId: 'ORD-9044',
                targetTool: 'issue_refund',
                intendedAmountCents: 22000,
                expectedLedgerDeltaCents: -22000,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect order ORD-9044 for Amina Diallo and issue a full refund of 22000 cents (reason: probe_sensor_rma).',
                faultConfig: [
                    'tamper_tools_list_cursor_cycle' => true,
                    'max_cursor_cycle_injections' => 5,
                    'tamper_stateless_request_state' => true,
                    'jitter_base_ms' => 10,
                    'spike_latency_ms' => 55,
                ],
                paginationLimit: 2,
            ),
            new ChaosScenario(
                id: 'gauntlet_all',
                title: 'Full Deterministic Chaos Gauntlet (Multi-Fault)',
                category: 'COMBINED_GAUNTLET',
                summary: 'Sequential compound storm on ORD-9044 (€220.00): HTTP 429 Retry-After (#1) -> Post-Commit Truncated JSON-RPC (#2) -> Delayed Recovery (#3).',
                targetOrderId: 'ORD-9044',
                targetTool: 'issue_refund',
                intendedAmountCents: 22000,
                expectedLedgerDeltaCents: -22000,
                expectedWriteCount: 1,
                agentPrompt: 'Inspect order ORD-9044 for Amina Diallo and issue a full refund of 22000 cents (reason: gauntlet_settlement_audit).',
                faultConfig: [
                    'pre_commit_429_occurrences' => [1],
                    'retry_after_seconds' => 1,
                    'truncate_post_commit_occurrences' => [2],
                    'truncate_keep_bytes' => 39,
                    'jitter_base_ms' => 16,
                    'spike_latency_ms' => 185,
                ],
            ),
        ];

        $indexed = [];
        foreach ($scenarios as $scenario) {
            $indexed[$scenario->id] = $scenario;
        }

        return $indexed;
    }

    public static function get(string $id): ChaosScenario
    {
        $all = self::all();
        if (!isset($all[$id])) {
            throw new \InvalidArgumentException(\sprintf('Unknown chaos scenario "%s". Available: %s', $id, implode(', ', array_keys($all))));
        }

        return $all[$id];
    }
}
