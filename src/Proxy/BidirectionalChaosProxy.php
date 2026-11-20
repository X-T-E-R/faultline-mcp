<?php

declare(strict_types=1);

namespace Faultline\Proxy;

use Faultline\Chaos\ChaosDecision;
use Faultline\Chaos\ChaosEngine;
use Faultline\Chaos\ChaosScenario;
use Faultline\Chaos\SeededPrng;
use Faultline\FlightRecorder\FlightRecorder;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bidirectional MCP JSON-RPC 2.0 Chaos Proxy & Idempotency Flight-Seal Interceptor.
 *
 * Operates identically over Streamable HTTP (PSR-7) and line-delimited STDIO streams.
 */
final class BidirectionalChaosProxy
{
    private readonly Psr17Factory $psr17;

    public function __construct(
        private readonly ChaosEngine $chaosEngine,
        private readonly FlightRecorder $recorder,
        ?Psr17Factory $psr17 = null,
    ) {
        $this->psr17 = $psr17 ?? new Psr17Factory();
    }

    /**
     * Proxies a Streamable HTTP MCP request through the deterministic ChaosEngine to the upstream MCP Server.
     */
    public function handleHttpRequest(
        ServerRequestInterface $request,
        Server $upstreamServer,
        string $runId,
        int $seed,
        ChaosScenario $scenario,
        string $track = 'unguarded',
        bool $applyRealDelay = false,
    ): ResponseInterface {
        $httpMethod = strtoupper($request->getMethod());
        if ('POST' !== $httpMethod) {
            $transport = new StreamableHttpTransport(
                request: $request,
                responseFactory: $this->psr17,
                streamFactory: $this->psr17,
                middleware: [new PassthroughMiddleware()],
            );

            return $upstreamServer->run($transport);
        }

        $rawRequest = (string) $request->getBody();
        $decoded = json_decode($rawRequest, true);
        if (!\is_array($decoded)) {
            $transport = new StreamableHttpTransport(
                request: $request->withBody($this->psr17->createStream($rawRequest)),
                responseFactory: $this->psr17,
                streamFactory: $this->psr17,
                middleware: [new PassthroughMiddleware()],
            );

            return $upstreamServer->run($transport);
        }

        $rpcMethod = (string) ($decoded['method'] ?? 'notification');
        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
        $toolName = 'tools/call' === $rpcMethod && \is_string($params['name'] ?? null) ? $params['name'] : null;
        $requestId = $decoded['id'] ?? null;

        $guardMode = 'enforce' === strtolower($request->getHeaderLine('X-Faultline-Guard'))
            || true === ($params['_meta']['faultline']['guard'] ?? false);
        $idempotencyKey = $this->extractIdempotencyKey($request, $params);
        $schemaHashHeader = $request->getHeaderLine('X-Faultline-Schema-Sha256');
        $schemaSha256 = '' !== $schemaHashHeader
            ? $schemaHashHeader
            : (\is_string($params['_meta']['faultline']['schema_sha256'] ?? null) ? $params['_meta']['faultline']['schema_sha256'] : '');

        // 1. Flight-Seal Idempotency Replay: if an earlier attempt already committed upstream before a wire fault,
        // replay the exact sealed CallToolResult without re-executing the mutating tool on upstream!
        if ($guardMode && null !== $idempotencyKey && 'tools/call' === $rpcMethod) {
            $sealed = $this->recorder->consumeSealedResult($runId, $idempotencyKey);
            if (null !== $sealed) {
                $replayBody = $this->rewriteJsonRpcId($sealed['result_json'], $requestId);
                $this->recorder->recordFrame(
                    runId: $runId,
                    track: $track,
                    direction: 'PROXY_IDEMPOTENT_SHORT_CIRCUIT',
                    rpcMethod: $rpcMethod,
                    toolName: $toolName,
                    httpStatus: 200,
                    latencyMs: 2.4,
                    faultInjected: null,
                    guardAction: 'SAFE_IDEMPOTENT_REPLAY',
                    rawRequest: $rawRequest,
                    rawResponse: $replayBody,
                    meta: [
                        'idempotency_key' => $idempotencyKey,
                        'replay_count' => $sealed['replay_count'],
                        'args_sha256' => $sealed['args_sha256'],
                    ],
                );

                return $this->psr17->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Faultline-Guard-Action', 'SAFE_IDEMPOTENT_REPLAY')
                    ->withBody($this->psr17->createStream($replayBody));
            }
        }

        $flatHeaders = [];
        foreach ($request->getHeaders() as $hKey => $hVals) {
            $flatHeaders[strtolower($hKey)] = implode(', ', $hVals);
        }

        // 2. Inbound Chaos Evaluation (e.g. HTTP 429 Retry-After, Schema Drift scale shift, Stateless state tamper)
        $inbound = $this->chaosEngine->evaluateInbound($runId, $seed, $scenario, $rawRequest, $decoded, $flatHeaders);
        $inDecision = $inbound['decision'];
        $forwardedBody = $inbound['mutated_body'];

        if ($applyRealDelay && $inDecision->simulatedLatencyMs > 0) {
            usleep(min($inDecision->simulatedLatencyMs, 250) * 1000);
        }

        if ('SHORT_CIRCUIT_HTTP' === $inDecision->action) {
            $respBody = $inDecision->overrideBody ?? '';
            $this->recorder->recordFrame(
                runId: $runId,
                track: $track,
                direction: 'CLIENT_TO_PROXY_BLOCKED',
                rpcMethod: $rpcMethod,
                toolName: $toolName,
                httpStatus: $inDecision->httpStatus,
                latencyMs: (float) $inDecision->simulatedLatencyMs,
                faultInjected: $inDecision->faultCode,
                guardAction: null,
                rawRequest: $rawRequest,
                rawResponse: $respBody,
                meta: $inDecision->meta + ['headers' => $inDecision->headers],
            );

            $response = $this->psr17->createResponse($inDecision->httpStatus)
                ->withBody($this->psr17->createStream($respBody));
            foreach ($inDecision->headers as $hName => $hVal) {
                $response = $response->withHeader($hName, $hVal);
            }

            return $response;
        }

        // 3. Forward to real Mcp\Server\Server via StreamableHttpTransport
        $upstreamReq = $request->withBody($this->psr17->createStream($forwardedBody));
        $transport = new StreamableHttpTransport(
            request: $upstreamReq,
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            middleware: [new PassthroughMiddleware()],
        );
        $upstreamResp = $upstreamServer->run($transport);
        $upstreamStatus = $upstreamResp->getStatusCode();
        $upstreamBody = (string) $upstreamResp->getBody();

        // 4. Seal committed upstream tool result BEFORE outbound chaos can sever/truncate the wire!
        $guardRecorded = null;
        if (
            $guardMode
            && null !== $idempotencyKey
            && null !== $toolName
            && 200 === $upstreamStatus
            && $this->isSuccessfulToolResponse($upstreamBody)
        ) {
            $argsSha = SeededPrng::canonicalSha256($params['arguments'] ?? new \stdClass());
            $this->recorder->sealIdempotentResult(
                runId: $runId,
                idempotencyKey: $idempotencyKey,
                toolName: $toolName,
                argsSha256: $argsSha,
                schemaSha256: $schemaSha256,
                resultJson: $upstreamBody,
            );
            $guardRecorded = 'SEALED_COMMITTED_INTENT';
        }

        // 5. Outbound Chaos Evaluation (Post-commit truncated JSON-RPC, post-commit 502, schema drift publish, cursor poison)
        $outDecision = $this->chaosEngine->evaluateOutbound(
            runId: $runId,
            seed: $seed,
            scenario: $scenario,
            inboundRpc: $decoded,
            upstreamResponseBody: $upstreamBody,
            upstreamHttpStatus: $upstreamStatus,
        );

        $effectiveFault = $outDecision->faultCode ?? $inDecision->faultCode;
        $totalLatencyMs = (float) ($inDecision->simulatedLatencyMs + $outDecision->simulatedLatencyMs);

        if ($applyRealDelay && $outDecision->simulatedLatencyMs > 0) {
            usleep(min($outDecision->simulatedLatencyMs, 250) * 1000);
        }

        $clientVisibleStatus = $outDecision->isFault() ? $outDecision->httpStatus : $upstreamStatus;
        $clientVisibleBody = $outDecision->overrideBody ?? $upstreamBody;

        $this->recorder->recordFrame(
            runId: $runId,
            track: $track,
            direction: 'BIDIRECTIONAL_RPC',
            rpcMethod: $rpcMethod,
            toolName: $toolName,
            httpStatus: $clientVisibleStatus,
            latencyMs: $totalLatencyMs,
            faultInjected: $effectiveFault,
            guardAction: $guardRecorded,
            rawRequest: $forwardedBody,
            rawResponse: $clientVisibleBody,
            meta: array_merge($inDecision->meta, $outDecision->meta, [
                'upstream_status' => $upstreamStatus,
                'upstream_bytes' => \strlen($upstreamBody),
                'delivered_bytes' => \strlen($clientVisibleBody),
                'idempotency_key' => $idempotencyKey,
            ]),
        );

        if ($outDecision->isFault()) {
            $faultResp = $this->psr17->createResponse($clientVisibleStatus)
                ->withBody($this->psr17->createStream($clientVisibleBody));
            foreach ($upstreamResp->getHeaders() as $name => $values) {
                if ('content-length' === strtolower($name)) {
                    continue;
                }
                foreach ($values as $value) {
                    $faultResp = $faultResp->withAddedHeader($name, $value);
                }
            }
            foreach ($outDecision->headers as $name => $value) {
                $faultResp = $faultResp->withHeader($name, $value);
            }

            return $faultResp;
        }

        return $upstreamResp->withBody($this->psr17->createStream($upstreamBody));
    }

    /**
     * Intercepts a single line-delimited STDIO JSON-RPC frame between a STDIO client and an upstream STDIO process.
     *
     * @param callable(string): string $sendUpstreamLine Writes one JSON-RPC line to upstream STDIN and reads one line back
     */
    public function handleStdioLine(
        string $rawLine,
        callable $sendUpstreamLine,
        string $runId,
        int $seed,
        ChaosScenario $scenario,
        string $track = 'stdio',
    ): ?string {
        $trimmed = trim($rawLine);
        if ('' === $trimmed) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (!\is_array($decoded)) {
            return $sendUpstreamLine($trimmed);
        }

        // Notifications carry no id and expect no reply line
        $isNotification = \array_key_exists('method', $decoded) && !\array_key_exists('id', $decoded);
        if ($isNotification) {
            $sendUpstreamLine($trimmed);

            return null;
        }

        $rpcMethod = (string) ($decoded['method'] ?? 'unknown');
        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
        $toolName = 'tools/call' === $rpcMethod && \is_string($params['name'] ?? null) ? $params['name'] : null;
        $requestId = $decoded['id'] ?? null;

        $guardMode = true === ($params['_meta']['faultline']['guard'] ?? false);
        $idempotencyKey = \is_string($params['_meta']['faultline']['idempotency_key'] ?? null)
            ? $params['_meta']['faultline']['idempotency_key']
            : null;

        if ($guardMode && null !== $idempotencyKey && 'tools/call' === $rpcMethod) {
            $sealed = $this->recorder->consumeSealedResult($runId, $idempotencyKey);
            if (null !== $sealed) {
                $replay = $this->rewriteJsonRpcId($sealed['result_json'], $requestId);
                $this->recorder->recordFrame(
                    runId: $runId,
                    track: $track,
                    direction: 'STDIO_IDEMPOTENT_REPLAY',
                    rpcMethod: $rpcMethod,
                    toolName: $toolName,
                    httpStatus: 200,
                    latencyMs: 1.8,
                    faultInjected: null,
                    guardAction: 'SAFE_IDEMPOTENT_REPLAY',
                    rawRequest: $trimmed,
                    rawResponse: $replay,
                    meta: ['idempotency_key' => $idempotencyKey, 'replay_count' => $sealed['replay_count']],
                );

                return $replay;
            }
        }

        $inbound = $this->chaosEngine->evaluateInbound($runId, $seed, $scenario, $trimmed, $decoded);
        $inDecision = $inbound['decision'];

        if ('SHORT_CIRCUIT_HTTP' === $inDecision->action) {
            $errPayload = json_encode([
                'jsonrpc' => '2.0',
                'id' => $requestId,
                'error' => [
                    'code' => -32029,
                    'message' => $inDecision->overrideBody ?? 'Rate limit exceeded',
                    'data' => ['http_status' => $inDecision->httpStatus, 'headers' => $inDecision->headers],
                ],
            ], \JSON_UNESCAPED_SLASHES);
            $errLine = false !== $errPayload ? $errPayload : '';

            $this->recorder->recordFrame(
                runId: $runId,
                track: $track,
                direction: 'STDIO_SHORT_CIRCUIT',
                rpcMethod: $rpcMethod,
                toolName: $toolName,
                httpStatus: $inDecision->httpStatus,
                latencyMs: (float) $inDecision->simulatedLatencyMs,
                faultInjected: $inDecision->faultCode,
                guardAction: null,
                rawRequest: $trimmed,
                rawResponse: $errLine,
                meta: $inDecision->meta,
            );

            return $errLine;
        }

        $upstreamLine = $sendUpstreamLine($inbound['mutated_body']);

        if ($guardMode && null !== $idempotencyKey && null !== $toolName && $this->isSuccessfulToolResponse($upstreamLine)) {
            $argsSha = SeededPrng::canonicalSha256($params['arguments'] ?? new \stdClass());
            $this->recorder->sealIdempotentResult(
                runId: $runId,
                idempotencyKey: $idempotencyKey,
                toolName: $toolName,
                argsSha256: $argsSha,
                schemaSha256: '',
                resultJson: $upstreamLine,
            );
        }

        $outDecision = $this->chaosEngine->evaluateOutbound(
            runId: $runId,
            seed: $seed,
            scenario: $scenario,
            inboundRpc: $decoded,
            upstreamResponseBody: $upstreamLine,
            upstreamHttpStatus: 200,
        );

        $deliveredLine = $outDecision->overrideBody ?? $upstreamLine;
        if ('POST_COMMIT_HTTP_502' === $outDecision->action) {
            $err502 = json_encode([
                'jsonrpc' => '2.0',
                'id' => $requestId,
                'error' => ['code' => -32603, 'message' => 'Upstream gateway connection reset after commit'],
            ], \JSON_UNESCAPED_SLASHES);
            $deliveredLine = false !== $err502 ? $err502 : $upstreamLine;
        }

        $this->recorder->recordFrame(
            runId: $runId,
            track: $track,
            direction: 'STDIO_BIDIRECTIONAL',
            rpcMethod: $rpcMethod,
            toolName: $toolName,
            httpStatus: $outDecision->httpStatus,
            latencyMs: (float) ($inDecision->simulatedLatencyMs + $outDecision->simulatedLatencyMs),
            faultInjected: $outDecision->faultCode ?? $inDecision->faultCode,
            guardAction: $guardMode && null !== $idempotencyKey ? 'SEALED_COMMITTED_INTENT' : null,
            rawRequest: $inbound['mutated_body'],
            rawResponse: $deliveredLine,
            meta: array_merge($inDecision->meta, $outDecision->meta),
        );

        return $deliveredLine;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function extractIdempotencyKey(ServerRequestInterface $request, array $params): ?string
    {
        $headerKey = trim($request->getHeaderLine('X-Faultline-Idempotency-Key'));
        if ('' !== $headerKey) {
            return $headerKey;
        }

        $metaKey = $params['_meta']['faultline']['idempotency_key'] ?? null;
        if (\is_string($metaKey) && '' !== trim($metaKey)) {
            return trim($metaKey);
        }

        $argKey = $params['arguments']['idempotencyKey'] ?? null;
        if (\is_string($argKey) && '' !== trim($argKey)) {
            return trim($argKey);
        }

        return null;
    }

    private function isSuccessfulToolResponse(string $body): bool
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !\is_array($decoded['result'] ?? null)) {
            return false;
        }

        return true !== ($decoded['result']['isError'] ?? false);
    }

    private function rewriteJsonRpcId(string $jsonPayload, int|string|null $newId): string
    {
        $decoded = json_decode($jsonPayload, true);
        if (!\is_array($decoded)) {
            return $jsonPayload;
        }

        $decoded['id'] = $newId;
        $encoded = json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false !== $encoded ? $encoded : $jsonPayload;
    }
}
