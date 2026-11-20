<?php

declare(strict_types=1);

namespace Faultline\Tests;

use Faultline\Http\FaultlineHttpController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpEndpointIntegrationTest extends TestCase
{
    public function testWebCockpitAndJsonDuelEndpoints(): void
    {
        $projectRoot = \dirname(__DIR__);
        $controller = new FaultlineHttpController($projectRoot, ':memory:');

        $homeResp = $controller->handle(Request::create('/', 'GET'));
        self::assertSame(200, $homeResp->getStatusCode());
        self::assertStringContainsString('FAULTLINE MCP', (string) $homeResp->getContent());

        $healthResp = $controller->handle(Request::create('/healthz', 'GET'));
        self::assertSame(200, $healthResp->getStatusCode());
        $health = json_decode((string) $healthResp->getContent(), true);
        self::assertIsArray($health);
        self::assertSame('ok', $health['status']);

        $bootResp = $controller->handle(Request::create('/api/scenarios', 'GET'));
        self::assertSame(200, $bootResp->getStatusCode());
        $boot = json_decode((string) $bootResp->getContent(), true);
        self::assertIsArray($boot);
        self::assertCount(6, $boot['scenarios']);
        self::assertSame('DOUBLE_REFUND_DETECTED', $boot['initial_duel']['unguarded']['evaluation']['verdict']);

        $duelReq = Request::create(
            '/api/duel',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['scenario_id' => 'http_429_storm', 'seed' => 20261123]),
        );
        $duelResp = $controller->handle($duelReq);
        self::assertSame(200, $duelResp->getStatusCode());
        $duel = json_decode((string) $duelResp->getContent(), true);
        self::assertIsArray($duel);
        self::assertSame('DOUBLE_CHARGE_DETECTED', $duel['unguarded']['evaluation']['verdict']);
        self::assertSame('INVARIANT_PRESERVED', $duel['guarded']['evaluation']['verdict']);
    }
}
