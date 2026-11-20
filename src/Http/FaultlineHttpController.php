<?php

declare(strict_types=1);

namespace Faultline\Http;

use Composer\InstalledVersions;
use Faultline\Agent\DuelRunner;
use Faultline\Chaos\ChaosScenarioCatalog;
use Faultline\Ledger\LedgerStore;
use Faultline\TargetServer\CommerceServerFactory;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP entrypoint routing Web Oscilloscope UI, JSON telemetry endpoints,
 * and live Streamable HTTP MCP endpoints (`/mcp/target` and `/mcp/proxy`).
 */
final class FaultlineHttpController
{
    private readonly LedgerStore $ledger;
    private readonly DuelRunner $duelRunner;
    private readonly Psr17Factory $psr17;
    private readonly PsrHttpFactory $psrHttpFactory;
    private readonly HttpFoundationFactory $httpFoundationFactory;

    public function __construct(
        private readonly string $projectRoot,
        ?string $dbPath = null,
    ) {
        $resolvedDb = $dbPath ?? (getenv('FAULTLINE_DB_PATH') ?: $this->projectRoot.'/var/faultline.sqlite');
        $this->ledger = new LedgerStore($resolvedDb);
        $this->duelRunner = new DuelRunner($this->ledger);
        $this->psr17 = new Psr17Factory();
        $this->psrHttpFactory = new PsrHttpFactory($this->psr17, $this->psr17, $this->psr17, $this->psr17);
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function handle(Request $request): Response
    {
        $path = rtrim($request->getPathInfo(), '/') ?: '/';
        $method = $request->getMethod();

        if ('OPTIONS' === $method && str_starts_with($path, '/api/')) {
            return $this->withCors(new Response('', 204));
        }

        return match (true) {
            '/' === $path || '/ui/oscilloscope' === $path => $this->serveOscilloscopeHtml(),
            '/healthz' === $path => $this->withCors(new JsonResponse([
                'status' => 'ok',
                'service' => 'faultline-mcp',
                'php_version' => \PHP_VERSION,
                'mcp_sdk_version' => InstalledVersions::getPrettyVersion('mcp/sdk') ?? 'v0.10.0',
                'symfony_ai_agent_version' => InstalledVersions::getPrettyVersion('symfony/ai-agent') ?? 'v1.0.0-BETA1',
            ])),
            '/api/scenarios' === $path && 'GET' === $method => $this->withCors($this->listScenariosAndBootstrap()),
            '/api/duel' === $path && 'POST' === $method => $this->withCors($this->executeDuelApi($request)),
            '/api/runs' === $path && 'GET' === $method => $this->withCors(new JsonResponse([
                'runs' => $this->duelRunner->getRecorder()->listRecentRuns(16),
            ])),
            '/api/ledger' === $path && 'GET' === $method => $this->withCors(new JsonResponse(
                $this->ledger->snapshot((string) $request->query->get('scope', 'default'))
            )),
            '/mcp/target' === $path => $this->withCors($this->handleMcpTarget($request)),
            '/mcp/proxy' === $path => $this->withCors($this->handleMcpProxy($request)),
            default => new JsonResponse(['error' => 'Route not found', 'path' => $path], 404),
        };
    }

    private function serveOscilloscopeHtml(): Response
    {
        $templatePath = $this->projectRoot.'/templates/oscilloscope.html';
        if (!is_file($templatePath)) {
            return new Response('Oscilloscope template missing.', 500);
        }

        return new Response(
            (string) file_get_contents($templatePath),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function listScenariosAndBootstrap(): JsonResponse
    {
        $scenarios = array_values(array_map(
            static fn ($s): array => $s->toArray(),
            ChaosScenarioCatalog::all(),
        ));

        $hfConfigured = '' !== trim((string) ($_ENV['HF_TOKEN'] ?? getenv('HF_TOKEN') ?: ''));
        $initialDuel = $this->duelRunner->runDuel('post_commit_truncation', 20261123, false, 'boot_post_commit_20261123');

        return new JsonResponse([
            'team' => 'XTerStudio · SFAI-6T8Y',
            'runtime' => [
                'php' => \PHP_VERSION,
                'mcp_sdk' => InstalledVersions::getPrettyVersion('mcp/sdk') ?? 'v0.10.0',
                'symfony_ai' => InstalledVersions::getPrettyVersion('symfony/ai-agent') ?? 'v1.0.0-BETA1',
                'hf_token_configured' => $hfConfigured,
                'protocols' => ['2025-11-25 (Handshake)', '2026-07-28 (Stateless)', 'io.modelcontextprotocol/ui (McpApps)'],
            ],
            'scenarios' => $scenarios,
            'initial_duel' => $initialDuel,
        ]);
    }

    private function executeDuelApi(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $scenarioId = \is_string($payload['scenario_id'] ?? null) ? $payload['scenario_id'] : 'post_commit_truncation';
        $seed = isset($payload['seed']) && \is_numeric($payload['seed']) ? (int) $payload['seed'] : 20261123;
        $liveHf = true === ($payload['use_live_hf'] ?? false);

        try {
            $result = $this->duelRunner->runDuel($scenarioId, $seed, $liveHf);

            return new JsonResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Duel execution failed: '.$e->getMessage()], 500);
        }
    }

    private function handleMcpTarget(Request $request): Response
    {
        $scope = (string) ($request->headers->get('X-Faultline-Scope') ?? $request->query->get('scope', 'default'));
        $pagination = max(1, (int) ($request->query->get('limit', '50')));
        $sessionDir = $this->projectRoot.'/var/mcp-http-target';

        $server = CommerceServerFactory::createServer(
            ledger: $this->ledger,
            scope: $scope,
            sessionStorageDir: $sessionDir,
            paginationLimit: $pagination,
        );

        $psrReq = $this->psrHttpFactory->createRequest($request);
        $transport = new StreamableHttpTransport(
            request: $psrReq,
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            middleware: [new PassthroughMiddleware()],
        );

        $psrResp = $server->run($transport);

        return $this->httpFoundationFactory->createResponse($psrResp);
    }

    private function handleMcpProxy(Request $request): Response
    {
        $scenarioId = (string) ($request->headers->get('X-Faultline-Scenario') ?? $request->query->get('scenario', 'post_commit_truncation'));
        $seed = (int) ($request->headers->get('X-Faultline-Seed') ?? $request->query->get('seed', '20261123'));
        $runId = (string) ($request->headers->get('X-Faultline-Run-Id') ?? $request->query->get('run_id', 'http_proxy_default'));
        $track = (string) ($request->headers->get('X-Faultline-Track') ?? $request->query->get('track', 'http_proxy'));

        $scenario = ChaosScenarioCatalog::get($scenarioId);
        $sessionDir = $this->projectRoot.'/var/mcp-http-proxy';

        $upstream = CommerceServerFactory::createServer(
            ledger: $this->ledger,
            scope: $track,
            sessionStorageDir: $sessionDir,
            paginationLimit: $scenario->paginationLimit,
        );

        $psrReq = $this->psrHttpFactory->createRequest($request);
        $psrResp = $this->duelRunner->getProxy()->handleHttpRequest(
            request: $psrReq,
            upstreamServer: $upstream,
            runId: $runId,
            seed: $seed,
            scenario: $scenario,
            track: $track,
        );

        return $this->httpFoundationFactory->createResponse($psrResp);
    }

    private function withCors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, X-Faultline-Guard, X-Faultline-Idempotency-Key, X-Faultline-Schema-Sha256, X-Faultline-Scenario, X-Faultline-Seed, X-Faultline-Run-Id');
        $response->headers->set('Access-Control-Expose-Headers', 'Mcp-Session-Id, Mcp-Protocol-Version, Retry-After, X-Faultline-Fault, X-Faultline-Guard-Action');

        return $response;
    }
}
