<?php

declare(strict_types=1);

namespace Faultline\Proxy;

use Faultline\Chaos\ChaosScenario;
use Mcp\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 HTTP Client adapter routing official `Mcp\Client\Transport\HttpTransport` wire frames
 * directly through `BidirectionalChaosProxy` and `Mcp\Server\Transport\StreamableHttpTransport`.
 *
 * Uses authentic PSR-7 wire serialization (including HTTP headers, Mcp-Session-Id, Retry-After,
 * and byte-level JSON truncation) with sub-millisecond deterministic execution.
 */
final class InProcessPsr18TransportBridge implements ClientInterface
{
    private readonly Psr17Factory $psr17;

    /** @var (\Closure(): array<string, string>)|null */
    private ?\Closure $dynamicHeadersProvider = null;

    /** @var array<string, string> */
    private array $lastResponseHeaders = [];

    private int $lastStatusCode = 200;

    public function __construct(
        private readonly BidirectionalChaosProxy $proxy,
        private readonly Server $upstreamServer,
        private readonly string $runId,
        private readonly int $seed,
        private readonly ChaosScenario $scenario,
        private readonly string $track = 'unguarded',
        ?Psr17Factory $psr17 = null,
    ) {
        $this->psr17 = $psr17 ?? new Psr17Factory();
    }

    /**
     * Allows middleware (`FaultlineGuardedToolset`) to attach per-invocation headers such as
     * `X-Faultline-Idempotency-Key` and `X-Faultline-Schema-Sha256`.
     *
     * @param (\Closure(): array<string, string>)|null $provider
     */
    public function setDynamicHeadersProvider(?\Closure $provider): void
    {
        $this->dynamicHeadersProvider = $provider;
    }

    /**
     * @return array<string, string>
     */
    public function getLastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    public function getLastStatusCode(): int
    {
        return $this->lastStatusCode;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $bodyContents = (string) $request->getBody();
        $serverRequest = $this->psr17->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            ['REMOTE_ADDR' => '127.0.0.1'],
        )->withBody($this->psr17->createStream($bodyContents));

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $serverRequest = $serverRequest->withAddedHeader($name, $value);
            }
        }

        if (null !== $this->dynamicHeadersProvider) {
            foreach (($this->dynamicHeadersProvider)() as $hName => $hVal) {
                $serverRequest = $serverRequest->withHeader($hName, $hVal);
            }
        }

        $response = $this->proxy->handleHttpRequest(
            request: $serverRequest,
            upstreamServer: $this->upstreamServer,
            runId: $this->runId,
            seed: $this->seed,
            scenario: $this->scenario,
            track: $this->track,
        );

        $this->lastStatusCode = $response->getStatusCode();
        $headersFlat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headersFlat[strtolower($name)] = implode(', ', $values);
        }
        $this->lastResponseHeaders = $headersFlat;

        return $response;
    }
}
