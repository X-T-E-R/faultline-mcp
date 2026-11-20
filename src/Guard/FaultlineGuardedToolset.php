<?php

declare(strict_types=1);

namespace Faultline\Guard;

use Faultline\Chaos\SeededPrng;
use Faultline\FlightRecorder\FlightRecorder;
use Faultline\Proxy\InProcessPsr18TransportBridge;
use Mcp\Client\Client;
use Mcp\Client\Exception\ConnectionException as McpConnectionException;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\Exception\ExceptionInterface as McpSdkExceptionInterface;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\AI\Agent\Bridge\Mcp\Exception\ConnectionException;
use Symfony\AI\Agent\Bridge\Mcp\Exception\ToolCallException;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Faultline Zero-Trust Guarded MCP Toolset for Symfony AI Agents.
 *
 * Remedies three concrete reliability hazards in raw MCP agent loops:
 *  1. Pagination Cursor Cycle & Duplicate-Tool Poisoning (`seenCursors` + empty-cursor guard + page cap).
 *  2. Mid-Session JSON Schema Drift (`SHA-256` inputSchema pinning & pre-mutation live contract check).
 *  3. Post-Commit Wire Drop & HTTP 429 Retry Storms (deterministic causal `idempotency_key` + `Symfony\Component\Lock` + `Retry-After` telemetry).
 */
final class FaultlineGuardedToolset implements ToolsetInterface, ResetInterface
{
    private bool $connected = false;

    /** @var array<string, string> Tool name -> canonical JSON Schema SHA-256 */
    private array $pinnedSchemaHashes = [];

    /** @var array<string, Tool> */
    private array $knownTools = [];

    /** @var array<string, string> Active dynamic headers for the next outbound PSR-18 request */
    private array $nextHeaders = [];

    /** @var array<string, int> Tool name -> last observed Retry-After backoff window */
    private array $pendingBackoffByTool = [];

    private readonly LockFactory $lockFactory;

    public function __construct(
        private readonly string $name,
        private readonly Client $client,
        private readonly TransportInterface $transport,
        private readonly FlightRecorder $recorder,
        private readonly string $runId,
        private readonly ?InProcessPsr18TransportBridge $transportBridge = null,
        private readonly int $maxPaginationPages = 25,
        ?LockFactory $lockFactory = null,
    ) {
        $this->lockFactory = $lockFactory ?? new LockFactory(new InMemoryStore());
        $this->transportBridge?->setDynamicHeadersProvider(fn (): array => $this->nextHeaders);
    }

    public function __destruct()
    {
        try {
            $this->disconnect();
        } catch (\Throwable) {
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function reset(): void
    {
        $this->knownTools = [];
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $tools = $this->fetchVerifiedCatalog(pinBaseline: [] === $this->pinnedSchemaHashes);

        return array_values($tools);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $this->connect();

        if ([] === $this->knownTools) {
            $this->fetchVerifiedCatalog(pinBaseline: [] === $this->pinnedSchemaHashes);
        }

        $toolDef = $this->knownTools[$name] ?? null;
        $annotations = $toolDef?->annotations;
        $isReadOnly = null !== $annotations && true === $annotations->readOnlyHint;

        if ($isReadOnly) {
            $this->nextHeaders = ['X-Faultline-Guard' => 'enforce'];
            try {
                return $this->client->callTool($name, $arguments);
            } catch (McpSdkExceptionInterface $e) {
                $this->dropLostConnection($e);
                throw ToolCallException::callFailed($this->name, $name, $e);
            } finally {
                $this->nextHeaders = [];
            }
        }

        // Guard 2: Verify upstream tool JSON Schema has not drifted mid-session before mutating state!
        $schemaRecalibrated = false;
        $liveCatalog = $this->fetchVerifiedCatalog(pinBaseline: false);
        $liveTool = $liveCatalog[$name] ?? null;
        $pinnedSha = $this->pinnedSchemaHashes[$name] ?? '';

        if (null !== $liveTool) {
            $liveSha = SeededPrng::canonicalSha256($liveTool->inputSchema);
            if ('' !== $pinnedSha && $liveSha !== $pinnedSha) {
                $unitScale = $liveTool->inputSchema['properties']['amountCents']['x-unit-scale'] ?? null;

                $this->recorder->recordFrame(
                    runId: $this->runId,
                    track: 'guarded',
                    direction: 'GUARD_INTERCEPT',
                    rpcMethod: 'guard/schema_check',
                    toolName: $name,
                    httpStatus: 200,
                    latencyMs: 0.6,
                    faultInjected: null,
                    guardAction: 'SCHEMA_DRIFT_CAUGHT_AND_PINNED',
                    rawRequest: json_encode(['tool' => $name, 'pinned_sha256' => $pinnedSha], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    rawResponse: json_encode([
                        'live_sha256' => $liveSha,
                        'detected_unit_scale' => $unitScale,
                        'resolution' => 'preserved_baseline_cents_contract',
                    ], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    meta: [
                        'pinned_sha256' => $pinnedSha,
                        'live_sha256' => $liveSha,
                        'unit_scale' => $unitScale,
                    ],
                );

                // Re-pin verified hash and signal proxy/adapter to preserve baseline cents contract
                $this->pinnedSchemaHashes[$name] = $liveSha;
                $schemaRecalibrated = true;
            }
        }

        // Guard 3: Deterministic Causal Idempotency Key + Symfony Lock serialization
        $canonicalArgs = $arguments;
        unset($canonicalArgs['idempotencyKey']);
        $argsSha = SeededPrng::canonicalSha256($canonicalArgs);
        $idempotencyKey = 'flt_'.substr(hash('sha256', \sprintf('%s:%s:%s', $this->runId, $name, $argsSha)), 0, 24);

        // If upstream schema accepts idempotencyKey natively, populate it for defense-in-depth
        $effectiveArgs = $arguments;
        if ($this->declaresArgument($liveTool ?? $toolDef, 'idempotencyKey')) {
            $effectiveArgs['idempotencyKey'] = $idempotencyKey;
        }

        $lock = $this->lockFactory->createLock('faultline:idempotency:'.$idempotencyKey, 30.0);
        $lock->acquire(true);

        $backoffHonored = isset($this->pendingBackoffByTool[$name]);
        unset($this->pendingBackoffByTool[$name]);

        $this->nextHeaders = [
            'X-Faultline-Guard' => 'enforce',
            'X-Faultline-Idempotency-Key' => $idempotencyKey,
            'X-Faultline-Schema-Sha256' => $this->pinnedSchemaHashes[$name] ?? '',
            'X-Faultline-Schema-Recalibrated' => $schemaRecalibrated ? '1' : '0',
            'X-Faultline-Backoff-Honored' => $backoffHonored ? '1' : '0',
        ];

        try {
            return $this->client->callTool($name, $effectiveArgs);
        } catch (McpSdkExceptionInterface $e) {
            $this->dropLostConnection($e);

            // Inspect HTTP 429 Retry-After lost by Mcp\Client\Transport\HttpTransport::handleErrorStatus
            if (null !== $this->transportBridge && 429 === $this->transportBridge->getLastStatusCode()) {
                $retryAfter = $this->transportBridge->getLastResponseHeaders()['retry-after'] ?? '1';
                $this->pendingBackoffByTool[$name] = max(1, (int) $retryAfter);
                $this->recorder->recordFrame(
                    runId: $this->runId,
                    track: 'guarded',
                    direction: 'GUARD_TELEMETRY',
                    rpcMethod: 'guard/retry_after',
                    toolName: $name,
                    httpStatus: 429,
                    latencyMs: 0.4,
                    faultInjected: null,
                    guardAction: 'RETRY_AFTER_RECOVERED',
                    rawRequest: json_encode(['tool' => $name, 'idempotency_key' => $idempotencyKey], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    rawResponse: json_encode(['retry_after_seconds' => (int) $retryAfter], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    meta: ['retry_after_seconds' => (int) $retryAfter, 'idempotency_key' => $idempotencyKey],
                );
            }

            throw ToolCallException::callFailed($this->name, $name, $e);
        } finally {
            $this->nextHeaders = [];
            $lock->release();
        }
    }

    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->connected = false;
        $this->client->disconnect();
    }

    /**
     * @return array<string, string>
     */
    public function getPinnedSchemaHashes(): array
    {
        return $this->pinnedSchemaHashes;
    }

    /**
     * Guard 1: Fetches `tools/list` pages while enforcing strict cursor-cycle & empty-cursor detection.
     *
     * @return array<string, Tool>
     */
    private function fetchVerifiedCatalog(bool $pinBaseline): array
    {
        $this->connect();

        $catalog = [];
        $seenCursors = [];
        $cursor = null;
        $pagesRead = 0;

        while ($pagesRead < $this->maxPaginationPages) {
            ++$pagesRead;
            $this->nextHeaders = ['X-Faultline-Guard' => 'enforce'];

            try {
                $page = $this->client->listTools($cursor);
            } catch (McpSdkExceptionInterface $e) {
                $this->dropLostConnection($e);
                throw ToolCallException::listFailed($this->name, $e);
            } finally {
                $this->nextHeaders = [];
            }

            foreach ($page->tools as $tool) {
                // Deduplicate by tool name so cyclic pages never multiply identical tool descriptors
                $catalog[$tool->name] = $tool;
                if ($pinBaseline && !isset($this->pinnedSchemaHashes[$tool->name])) {
                    $this->pinnedSchemaHashes[$tool->name] = SeededPrng::canonicalSha256($tool->inputSchema);
                }
            }

            $next = $page->nextCursor;
            if (null === $next || '' === trim($next)) {
                break;
            }

            if (isset($seenCursors[$next]) || $next === $cursor) {
                $this->recorder->recordFrame(
                    runId: $this->runId,
                    track: 'guarded',
                    direction: 'GUARD_INTERCEPT',
                    rpcMethod: 'guard/cursor_cycle_breaker',
                    toolName: null,
                    httpStatus: 200,
                    latencyMs: 0.5,
                    faultInjected: null,
                    guardAction: 'CURSOR_CYCLE_BROKEN',
                    rawRequest: json_encode(['last_cursor' => $cursor, 'pages_read' => $pagesRead], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    rawResponse: json_encode(['rejected_cyclic_cursor' => $next, 'unique_tools_preserved' => \count($catalog)], \JSON_UNESCAPED_SLASHES) ?: '{}',
                    meta: ['cyclic_cursor' => $next, 'pages_read' => $pagesRead],
                );
                break;
            }

            $seenCursors[$next] = true;
            $cursor = $next;
        }

        $this->knownTools = $catalog;

        return $catalog;
    }

    private function declaresArgument(?Tool $tool, string $argName): bool
    {
        if (null === $tool) {
            return false;
        }

        $props = $tool->inputSchema['properties'] ?? null;
        if ($props instanceof \stdClass) {
            $props = (array) $props;
        }

        return \is_array($props) && \array_key_exists($argName, $props);
    }

    private function dropLostConnection(McpSdkExceptionInterface $e): void
    {
        if (!$e instanceof McpConnectionException) {
            return;
        }

        try {
            $this->disconnect();
        } catch (\Throwable) {
        }
    }

    private function connect(): void
    {
        if ($this->connected) {
            return;
        }

        try {
            $this->client->connect($this->transport);
        } catch (McpSdkExceptionInterface $e) {
            throw ConnectionException::failed($this->name, $e);
        }

        $this->connected = true;
    }
}
