<?php

declare(strict_types=1);

namespace Faultline\Tests;

use Faultline\Ledger\LedgerStore;
use Faultline\TargetServer\CommerceMcpCapabilities;
use Faultline\TargetServer\CommerceServerFactory;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class McpProtocolConformanceTest extends TestCase
{
    public function testHandshakeEra20251125OverStreamableHttpMintsSessionAndNegotiatesMcpApps(): void
    {
        $ledger = new LedgerStore(':memory:');
        $ledger->resetScope('handshake_test');

        $server = CommerceServerFactory::createServer(
            ledger: $ledger,
            scope: 'handshake_test',
            sessionStorageDir: null,
        );

        $psr17 = new Psr17Factory();
        $httpClient = new class($server, $psr17) implements ClientInterface {
            public function __construct(
                private readonly \Mcp\Server\Server $server,
                private readonly Psr17Factory $psr17,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $srvReq = $this->psr17->createServerRequest($request->getMethod(), $request->getUri())
                    ->withBody($this->psr17->createStream((string) $request->getBody()));
                foreach ($request->getHeaders() as $name => $values) {
                    foreach ($values as $v) {
                        $srvReq = $srvReq->withAddedHeader($name, $v);
                    }
                }

                return $this->server->run(new StreamableHttpTransport(
                    request: $srvReq,
                    responseFactory: $this->psr17,
                    streamFactory: $this->psr17,
                    middleware: [new PassthroughMiddleware()],
                ));
            }
        };

        $transport = new HttpTransport(
            endpoint: 'http://localhost:8080/mcp/target',
            httpClient: $httpClient,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client = Client::builder()
            ->setClientInfo('conformance-handshake', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->enableExtension(new McpApps())
            ->build();

        $client->connect($transport);

        try {
            self::assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
            self::assertNotNull($transport->getSessionId(), 'Handshake era must mint an Mcp-Session-Id.');

            $toolsPage = $client->listTools();
            $toolNames = array_map(static fn ($t): string => $t->name, $toolsPage->tools);
            self::assertContains('get_order', $toolNames);
            self::assertContains('issue_refund', $toolNames);
            self::assertContains('open_faultline_oscilloscope', $toolNames);

            $uiTool = array_values(array_filter(
                $toolsPage->tools,
                static fn ($t): bool => 'open_faultline_oscilloscope' === $t->name,
            ))[0];
            self::assertIsArray($uiTool->meta);
            self::assertArrayHasKey('ui', $uiTool->meta);

            $resourcesPage = $client->listResources();
            $uris = array_map(static fn ($r): string => $r->uri, $resourcesPage->resources);
            self::assertContains(CommerceMcpCapabilities::UI_RESOURCE_URI, $uris);

            $uiRead = $client->readResource(CommerceMcpCapabilities::UI_RESOURCE_URI);
            self::assertNotEmpty($uiRead->contents);
            $firstContent = $uiRead->contents[0];
            self::assertInstanceOf(TextResourceContents::class, $firstContent);
            self::assertSame(McpApps::MIME_TYPE, $firstContent->mimeType);
            self::assertStringContainsString('FAULTLINE MCP', $firstContent->text);
        } finally {
            $client->disconnect();
        }
    }

    public function testModernStatelessEra20260728OverStatelessHttpTransport(): void
    {
        $ledger = new LedgerStore(':memory:');
        $ledger->resetScope('stateless_test');

        $statelessProtocol = CommerceServerFactory::createStatelessProtocol(
            ledger: $ledger,
            scope: 'stateless_test',
        );

        $psr17 = new Psr17Factory();
        $statelessTransport = new StatelessHttpTransport(
            protocol: $statelessProtocol,
            responseFactory: $psr17,
            streamFactory: $psr17,
            middleware: [],
        );

        $httpClient = new class($statelessTransport, $psr17) implements ClientInterface {
            public function __construct(
                private readonly StatelessHttpTransport $transport,
                private readonly Psr17Factory $psr17,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $srvReq = $this->psr17->createServerRequest($request->getMethod(), $request->getUri())
                    ->withBody($this->psr17->createStream((string) $request->getBody()));
                foreach ($request->getHeaders() as $name => $values) {
                    foreach ($values as $v) {
                        $srvReq = $srvReq->withAddedHeader($name, $v);
                    }
                }

                return $this->transport->handle($srvReq);
            }
        };

        $transport = new HttpTransport(
            endpoint: 'http://localhost:8080/mcp/stateless',
            httpClient: $httpClient,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client = Client::builder()
            ->setClientInfo('conformance-modern', '1.0.0')
            ->enableExtension(new McpApps())
            ->build();

        $client->connect($transport);

        try {
            self::assertSame(ProtocolVersion::V2026_07_28, $client->getProtocolVersion());
            self::assertNull($transport->getSessionId(), 'Stateless 2026-07-28 revision must not use Mcp-Session-Id.');

            $result = $client->callTool('get_order', ['orderId' => 'ORD-9042']);
            self::assertFalse($result->isError);
            self::assertNotEmpty($result->content);
            self::assertInstanceOf(TextContent::class, $result->content[0]);
            self::assertStringContainsString('Clara Oswald', (string) $result->content[0]->text);
        } finally {
            $client->disconnect();
        }
    }

    public function testStdioTransportTargetAndChaosProxySubprocesses(): void
    {
        $projectRoot = \dirname(__DIR__);
        $tmpDb = sys_get_temp_dir().'/faultline_stdio_'.bin2hex(random_bytes(4)).'.sqlite';

        try {
            $ledger = new LedgerStore($tmpDb);
            $ledger->resetScope('stdio_proxy_track');

            $proxyScript = $projectRoot.'/bin/mcp-proxy-stdio';
            $targetScript = $projectRoot.'/bin/mcp-target-stdio';

            $stdioTransport = new StdioTransport(
                command: \PHP_BINARY,
                args: [$proxyScript],
                cwd: $projectRoot,
                env: array_merge(getenv(), [
                    'FAULTLINE_DB_PATH' => $tmpDb,
                    'FAULTLINE_SCENARIO' => 'post_commit_truncation',
                    'FAULTLINE_SEED' => '20261123',
                    'FAULTLINE_RUN_ID' => 'stdio_test_run',
                    'FAULTLINE_TRACK' => 'stdio_proxy_track',
                    'FAULTLINE_TARGET_SCRIPT' => $targetScript,
                ]),
            );

            $client = Client::builder()
                ->setClientInfo('stdio-chaos-tester', '1.0.0')
                ->setRequestTimeout(10)
                ->build();

            $client->connect($stdioTransport);

            try {
                $orderRes = $client->callTool('get_order', ['orderId' => 'ORD-9042']);
                self::assertFalse($orderRes->isError);

                // Attempt #1 over STDIO proxy triggers deterministic post-commit JSON truncation!
                $truncatedExceptionCaught = false;
                try {
                    $client->callTool('issue_refund', [
                        'orderId' => 'ORD-9042',
                        'amountCents' => 14900,
                        'reason' => 'stdio_truncation_probe',
                    ]);
                } catch (\Throwable) {
                    $truncatedExceptionCaught = true;
                }

                self::assertTrue($truncatedExceptionCaught, 'STDIO Chaos Proxy must inject post-commit frame truncation on attempt #1.');

                // Verify SQLite side effect WAS already committed despite client receiving severed frame!
                $entries = $ledger->listEntries('ORD-9042', 'stdio_proxy_track');
                self::assertCount(1, $entries);
                self::assertSame(-14900, $entries[0]['delta_cents']);
            } finally {
                $client->disconnect();
            }
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($tmpDb.$suffix)) {
                    @unlink($tmpDb.$suffix);
                }
            }
        }
    }

    public function testStatelessRequestStateHmacTamperIsRejectedByRequestStateCodec(): void
    {
        $ledger = new LedgerStore(':memory:');
        $recorder = new \Faultline\FlightRecorder\FlightRecorder($ledger->getPdo());
        $engine = new \Faultline\Chaos\ChaosEngine($recorder);
        $scenario = \Faultline\Chaos\ChaosScenarioCatalog::get('cursor_tamper');

        $codec = new \Mcp\Server\Stateless\RequestStateCodec(CommerceServerFactory::DEFAULT_SIGNING_KEY, 600);
        $minted = $codec->mint(['round' => 1, 'orderId' => 'ORD-9044']);

        $validDecoded = $codec->verify($minted);
        self::assertSame('ORD-9044', $validDecoded['orderId']);

        $inboundPayload = [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'issue_refund',
                'arguments' => ['orderId' => 'ORD-9044', 'amountCents' => 22000, 'reason' => 'rma'],
                '_meta' => [
                    'io.modelcontextprotocol/request-state' => $minted,
                ],
            ],
        ];
        $raw = (string) json_encode($inboundPayload, \JSON_UNESCAPED_SLASHES);

        $eval = $engine->evaluateInbound('tamper_run', 20261123, $scenario, $raw, $inboundPayload);
        self::assertSame('STATELESS_STATE_HMAC_TAMPER', $eval['decision']->faultCode);

        /** @var array<string, mixed> $mutated */
        $mutated = json_decode($eval['mutated_body'], true);
        $forgedToken = (string) $mutated['params']['_meta']['io.modelcontextprotocol/request-state'];

        $this->expectException(\Mcp\Server\Exception\RequestStateException::class);
        $codec->verify($forgedToken);
    }
}
