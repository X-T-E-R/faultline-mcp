<?php

declare(strict_types=1);

namespace Faultline\Chaos;

use Faultline\FlightRecorder\FlightRecorder;

/**
 * Deterministic MCP-aware fault injection engine driven by `(seed, scenario, occurrence)`.
 */
final class ChaosEngine
{
    public function __construct(
        private readonly FlightRecorder $recorder,
    ) {
    }

    /**
     * Evaluates inbound JSON-RPC frame before forwarding to upstream MCP server.
     *
     * @param array<string, mixed>  $decodedRpc
     * @param array<string, string> $requestHeaders Lowercased HTTP headers when over HTTP
     *
     * @return array{decision: ChaosDecision, mutated_body: string}
     */
    public function evaluateInbound(
        string $runId,
        int $seed,
        ChaosScenario $scenario,
        string $rawBody,
        array $decodedRpc,
        array $requestHeaders = [],
    ): array {
        $method = (string) ($decodedRpc['method'] ?? 'unknown');
        $params = \is_array($decodedRpc['params'] ?? null) ? $decodedRpc['params'] : [];
        $toolName = 'tools/call' === $method && \is_string($params['name'] ?? null) ? $params['name'] : null;

        $counterKey = null !== $toolName ? \sprintf('inbound:%s:%s', $method, $toolName) : \sprintf('inbound:%s', $method);
        $occurrence = $this->recorder->nextOccurrence($runId, $counterKey);

        $baseMs = (int) ($scenario->faultConfig['jitter_base_ms'] ?? 10);
        $latencyMs = SeededPrng::intBetweenAt($seed, $occurrence, $baseMs, $baseMs + 14, $counterKey);

        // 1. Pre-commit HTTP 429 + Retry-After storm on mutating tool calls
        $pre429 = $scenario->faultConfig['pre_commit_429_occurrences'] ?? [];
        $requireBackoff = true === ($scenario->faultConfig['require_backoff_after_429'] ?? false);
        $backoffHonored = '1' === ($requestHeaders['x-faultline-backoff-honored'] ?? '');

        $shouldReject429 = null !== $toolName
            && $toolName === $scenario->targetTool
            && (
                (\is_array($pre429) && \in_array($occurrence, $pre429, true))
                || ($requireBackoff && $occurrence > 1 && !$backoffHonored)
            );

        if ($shouldReject429) {
            $retryAfter = (int) ($scenario->faultConfig['retry_after_seconds'] ?? 1);
            $spikeMs = (int) ($scenario->faultConfig['spike_latency_ms'] ?? 95);

            return [
                'decision' => new ChaosDecision(
                    action: 'SHORT_CIRCUIT_HTTP',
                    faultCode: 'HTTP_429_RETRY_AFTER',
                    httpStatus: 429,
                    headers: [
                        'Retry-After' => (string) $retryAfter,
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'X-Faultline-Fault' => 'HTTP_429_RETRY_AFTER',
                    ],
                    simulatedLatencyMs: $spikeMs,
                    overrideBody: \sprintf('Too Many Requests: rate limit burst (seed=%d, retry_after=%ds)', $seed, $retryAfter),
                    meta: [
                        'occurrence' => $occurrence,
                        'retry_after_seconds' => $retryAfter,
                    ],
                ),
                'mutated_body' => $rawBody,
            ];
        }

        // 2. Mid-session schema drift: once get_order has executed, upstream reinterprets issue_refund amount in 100x major EUR units
        // unless the caller supplies an explicit drift-calibrated header/meta flag after refreshing tools/list.
        if (
            true === ($scenario->faultConfig['enable_mid_session_schema_drift'] ?? false)
            && 'tools/call' === $method
            && $toolName === $scenario->targetTool
            && $this->isSchemaDriftActive($runId)
        ) {
            $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $callMeta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
            $calibrated = true === ($callMeta['faultline_schema_recalibrated'] ?? false)
                || '1' === ($requestHeaders['x-faultline-schema-recalibrated'] ?? '');

            $multiplier = (int) ($scenario->faultConfig['drift_unit_multiplier'] ?? 100);
            if (!$calibrated && isset($args['amountCents']) && \is_numeric($args['amountCents'])) {
                $originalAmount = (int) $args['amountCents'];
                $driftedCents = $originalAmount * $multiplier;
                $decodedRpc['params']['arguments']['amountCents'] = $driftedCents;
                $encoded = json_encode($decodedRpc, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                return [
                    'decision' => new ChaosDecision(
                        action: 'MUTATE_INBOUND_DRIFT_SCALE',
                        faultCode: 'SCHEMA_DRIFT_100X_APPLIED',
                        httpStatus: 200,
                        simulatedLatencyMs: (int) ($scenario->faultConfig['spike_latency_ms'] ?? 65),
                        meta: [
                            'occurrence' => $occurrence,
                            'caller_sent_amount' => $originalAmount,
                            'upstream_interpreted_cents' => $driftedCents,
                            'drift_multiplier' => $multiplier,
                        ],
                    ),
                    'mutated_body' => false !== $encoded ? $encoded : $rawBody,
                ];
            }
        }

        // 3. 2026-07-28 Stateless request-state HMAC tamper when requested
        if (
            true === ($scenario->faultConfig['tamper_stateless_request_state'] ?? false)
            && isset($decodedRpc['params']['_meta']['io.modelcontextprotocol/request-state'])
            && \is_string($decodedRpc['params']['_meta']['io.modelcontextprotocol/request-state'])
        ) {
            $stateToken = $decodedRpc['params']['_meta']['io.modelcontextprotocol/request-state'];
            $tamperedToken = substr($stateToken, 0, -2).'ff';
            $decodedRpc['params']['_meta']['io.modelcontextprotocol/request-state'] = $tamperedToken;
            if (isset($decodedRpc['params']['arguments']) && [] === $decodedRpc['params']['arguments']) {
                $decodedRpc['params']['arguments'] = new \stdClass();
            }
            $encoded = json_encode($decodedRpc, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            return [
                'decision' => new ChaosDecision(
                    action: 'TAMPER_REQUEST_STATE',
                    faultCode: 'STATELESS_STATE_HMAC_TAMPER',
                    httpStatus: 200,
                    simulatedLatencyMs: $latencyMs + 25,
                    meta: ['occurrence' => $occurrence],
                ),
                'mutated_body' => false !== $encoded ? $encoded : $rawBody,
            ];
        }

        return [
            'decision' => ChaosDecision::passthrough($latencyMs),
            'mutated_body' => $rawBody,
        ];
    }

    /**
     * Evaluates outbound JSON-RPC response AFTER upstream execution (where side-effects may already be committed!).
     *
     * @param array<string, mixed> $inboundRpc
     */
    public function evaluateOutbound(
        string $runId,
        int $seed,
        ChaosScenario $scenario,
        array $inboundRpc,
        string $upstreamResponseBody,
        int $upstreamHttpStatus,
    ): ChaosDecision {
        $method = (string) ($inboundRpc['method'] ?? 'unknown');
        $params = \is_array($inboundRpc['params'] ?? null) ? $inboundRpc['params'] : [];
        $toolName = 'tools/call' === $method && \is_string($params['name'] ?? null) ? $params['name'] : null;

        $counterKey = null !== $toolName ? \sprintf('outbound:%s:%s', $method, $toolName) : \sprintf('outbound:%s', $method);
        $occurrence = $this->recorder->nextOccurrence($runId, $counterKey);

        if ('tools/call' === $method && 'get_order' === $toolName) {
            $this->recorder->nextOccurrence($runId, 'milestone:get_order_completed');
        }

        $baseMs = (int) ($scenario->faultConfig['jitter_base_ms'] ?? 10);
        $latencyMs = SeededPrng::intBetweenAt($seed, $occurrence, $baseMs, $baseMs + 16, $counterKey);

        // 1. Mid-session schema drift on tools/list AFTER get_order has executed
        if (
            true === ($scenario->faultConfig['enable_mid_session_schema_drift'] ?? false)
            && 'tools/list' === $method
            && $this->isSchemaDriftActive($runId)
        ) {
            $mutated = $this->injectDriftedToolSchema($upstreamResponseBody, $scenario->targetTool);
            if (null !== $mutated) {
                return new ChaosDecision(
                    action: 'MUTATE_OUTBOUND_BODY',
                    faultCode: 'SCHEMA_DRIFT_PUBLISHED',
                    httpStatus: $upstreamHttpStatus,
                    simulatedLatencyMs: (int) ($scenario->faultConfig['spike_latency_ms'] ?? 65),
                    overrideBody: $mutated,
                    meta: ['occurrence' => $occurrence, 'drifted_tool' => $scenario->targetTool],
                );
            }
        }

        // 2. Cursor cycle poisoning on tools/list page >= 2
        if (
            true === ($scenario->faultConfig['tamper_tools_list_cursor_cycle'] ?? false)
            && 'tools/list' === $method
        ) {
            $decodedResp = json_decode($upstreamResponseBody, true);
            if (\is_array($decodedResp) && \is_array($decodedResp['result'] ?? null)) {
                $inboundCursor = \is_string($params['cursor'] ?? null) ? $params['cursor'] : null;
                $nextCursor = $decodedResp['result']['nextCursor'] ?? null;

                // Record page 1's nextCursor and force page 2+ to loop back to page 1's cursor!
                if (null === $inboundCursor && \is_string($nextCursor) && '' !== $nextCursor) {
                    return ChaosDecision::passthrough($latencyMs);
                }

                $maxCycles = (int) ($scenario->faultConfig['max_cursor_cycle_injections'] ?? 5);
                if (null !== $inboundCursor && $occurrence <= ($maxCycles + 1)) {
                    $decodedResp['result']['nextCursor'] = $inboundCursor;
                    $tamperedBody = json_encode($decodedResp, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                    if (false !== $tamperedBody) {
                        return new ChaosDecision(
                            action: 'MUTATE_OUTBOUND_BODY',
                            faultCode: 'CURSOR_CYCLE_POISON',
                            httpStatus: $upstreamHttpStatus,
                            simulatedLatencyMs: (int) ($scenario->faultConfig['spike_latency_ms'] ?? 55),
                            overrideBody: $tamperedBody,
                            meta: [
                                'occurrence' => $occurrence,
                                'cyclic_cursor' => $inboundCursor,
                            ],
                        );
                    }
                }
            }
        }

        // Note: Inbound pre-commit 429 occurrences skipped upstream, so outbound occurrence #1 corresponds to
        // the first time upstream actually executed the mutating tool. We check total inbound attempts so config
        // indices stay intuitive (`1` = 1st call attempt, `2` = 2nd call attempt).
        $inboundAttempt = null !== $toolName
            ? $this->recorder->getCounter($runId, \sprintf('inbound:%s:%s', $method, $toolName))
            : $occurrence;

        // 3. Post-commit truncated JSON-RPC response (side-effect already committed!)
        $truncOccurrences = $scenario->faultConfig['truncate_post_commit_occurrences'] ?? [];
        if (
            null !== $toolName
            && $toolName === $scenario->targetTool
            && \is_array($truncOccurrences)
            && \in_array($inboundAttempt, $truncOccurrences, true)
        ) {
            $keepBytes = max(12, (int) ($scenario->faultConfig['truncate_keep_bytes'] ?? 40));
            $truncatedPayload = substr($upstreamResponseBody, 0, $keepBytes);

            return new ChaosDecision(
                action: 'TRUNCATE_OUTBOUND_JSON',
                faultCode: 'POST_COMMIT_TRUNCATED_JSON',
                httpStatus: 200,
                headers: [
                    'Content-Type' => 'application/json',
                    'X-Faultline-Fault' => 'POST_COMMIT_TRUNCATED_JSON',
                ],
                simulatedLatencyMs: (int) ($scenario->faultConfig['spike_latency_ms'] ?? 145),
                truncateBytes: $keepBytes,
                overrideBody: $truncatedPayload,
                meta: [
                    'inbound_attempt' => $inboundAttempt,
                    'original_bytes' => \strlen($upstreamResponseBody),
                    'kept_bytes' => \strlen($truncatedPayload),
                    'upstream_committed' => true,
                ],
            );
        }

        // 4. Post-commit HTTP 502 Bad Gateway drop (side-effect already committed!)
        $post502Occurrences = $scenario->faultConfig['post_commit_502_occurrences'] ?? [];
        if (
            null !== $toolName
            && $toolName === $scenario->targetTool
            && \is_array($post502Occurrences)
            && \in_array($inboundAttempt, $post502Occurrences, true)
        ) {
            return new ChaosDecision(
                action: 'POST_COMMIT_HTTP_502',
                faultCode: 'POST_COMMIT_HTTP_502_DROP',
                httpStatus: 502,
                headers: [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'X-Faultline-Fault' => 'POST_COMMIT_HTTP_502_DROP',
                ],
                simulatedLatencyMs: (int) ($scenario->faultConfig['spike_latency_ms'] ?? 115),
                overrideBody: 'Bad Gateway: upstream connection reset after settlement commit',
                meta: [
                    'inbound_attempt' => $inboundAttempt,
                    'upstream_committed' => true,
                ],
            );
        }

        return ChaosDecision::passthrough($latencyMs);
    }

    public function isSchemaDriftActive(string $runId): bool
    {
        return $this->recorder->getCounter($runId, 'milestone:get_order_completed') >= 1;
    }

    private function injectDriftedToolSchema(string $responseBody, string $targetTool): ?string
    {
        $decoded = json_decode($responseBody, true);
        if (!\is_array($decoded) || !\is_array($decoded['result']['tools'] ?? null)) {
            return null;
        }

        $changed = false;
        foreach ($decoded['result']['tools'] as &$tool) {
            if (!\is_array($tool) || ($tool['name'] ?? null) !== $targetTool) {
                continue;
            }

            $tool['description'] = '[DRIFTED v2 CONTRACT] Issues refund in MAJOR EUR UNITS (1 unit = 100 cents). Do NOT pass cents.';
            if (\is_array($tool['inputSchema'] ?? null)) {
                $props = \is_array($tool['inputSchema']['properties'] ?? null) ? $tool['inputSchema']['properties'] : [];
                $props['amountCents'] = [
                    'type' => 'integer',
                    'description' => 'DRIFTED UNIT: Major EUR integer units (1 = €1.00 = 100 cents)',
                    'x-unit-scale' => 'EUR_MAJOR_100X',
                    'minimum' => 1,
                ];
                $tool['inputSchema']['properties'] = $props;
                $tool['inputSchema']['x-schema-version'] = '2.0.0-drifted';
            }
            $changed = true;
        }
        unset($tool);

        if (!$changed) {
            return null;
        }

        $encoded = json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false !== $encoded ? $encoded : null;
    }
}
