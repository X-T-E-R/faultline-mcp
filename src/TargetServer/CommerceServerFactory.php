<?php

declare(strict_types=1);

namespace Faultline\TargetServer;

use Faultline\Ledger\LedgerStore;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use Mcp\Server\Stateless\StatelessProtocol;

/**
 * Builds the reference Symfony / PHP MCP SDK 1.0 Commerce & Refund server for STDIO and Streamable HTTP.
 */
final class CommerceServerFactory
{
    public const DEFAULT_SIGNING_KEY = 'faultline-mcp-stateless-hmac-key-2026-xterstudio-sfai6t8y';

    /**
     * @param (\Closure(): string)|null $uiHtmlProvider
     */
    public static function createBuilder(
        LedgerStore $ledger,
        string $scope = 'default',
        ?string $sessionStorageDir = null,
        int $paginationLimit = 50,
        string $requestStateSigningKey = self::DEFAULT_SIGNING_KEY,
        ?\Closure $uiHtmlProvider = null,
    ): Builder {
        $capabilities = new CommerceMcpCapabilities($ledger, $scope, $uiHtmlProvider);

        $builder = Server::builder()
            ->setServerInfo(
                name: 'faultline-commerce-ledger',
                version: '1.0.0',
                description: 'Reference Symfony e-commerce settlement & refund MCP server for deterministic chaos verification.',
                title: 'Faultline Commerce Ledger',
            )
            ->setInstructions(
                'Inspect orders with get_order before issuing refunds or payment captures. '
                .'Mutating tools (issue_refund, capture_payment) debit or credit real SQLite settlement balances.'
            )
            ->setPaginationLimit($paginationLimit)
            ->setRequestState($requestStateSigningKey, 600, new OriginatingRequestBinder())
            ->enableExtension(new McpApps())
            ->addTool(
                handler: [$capabilities, 'getOrder'],
                name: 'get_order',
                title: 'Inspect Order Settlement',
                description: 'Reads the current authorization, capture, and refund balances in cents for an e-commerce order.',
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
            )
            ->addTool(
                handler: [$capabilities, 'issueRefund'],
                name: 'issue_refund',
                title: 'Issue Customer Refund',
                description: 'Debits the merchant settlement account and refunds integer cents (amountCents) to the customer order.',
                annotations: new ToolAnnotations(
                    readOnlyHint: false,
                    destructiveHint: true,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
            )
            ->addTool(
                handler: [$capabilities, 'capturePayment'],
                name: 'capture_payment',
                title: 'Capture Order Payment',
                description: 'Captures authorized integer cents (amountCents) on an order and credits the merchant ledger.',
                annotations: new ToolAnnotations(
                    readOnlyHint: false,
                    destructiveHint: true,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
            )
            ->addTool(
                handler: [$capabilities, 'listLedgerEntries'],
                name: 'list_ledger_entries',
                title: 'List Settlement Ledger Entries',
                description: 'Lists immutable double-entry settlement rows, optionally filtered by orderId.',
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
            )
            ->addTool(
                handler: [$capabilities, 'openFaultlineOscilloscope'],
                name: 'open_faultline_oscilloscope',
                title: 'Open Faultline Oscilloscope',
                description: 'Opens the interactive MCP Apps (ui://) JSON-RPC oscilloscope and side-effect ledger diff inspector.',
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                meta: [
                    'ui' => new UiToolMeta(
                        resourceUri: CommerceMcpCapabilities::UI_RESOURCE_URI,
                        visibility: [ToolVisibility::Model, ToolVisibility::App],
                    ),
                ],
            )
            ->addResource(
                handler: [$capabilities, 'renderOscilloscopeApp'],
                uri: CommerceMcpCapabilities::UI_RESOURCE_URI,
                name: 'faultline_oscilloscope',
                title: 'Faultline MCP Oscilloscope & Trace Diff Inspector',
                description: 'Interactive MCP Apps (io.modelcontextprotocol/ui) cockpit displaying JSON-RPC wire frames and ledger diffs.',
                mimeType: McpApps::MIME_TYPE,
                meta: ['ui' => McpApps::resourceMarker()],
            )
            ->addResource(
                handler: [$capabilities, 'readLedgerSnapshot'],
                uri: CommerceMcpCapabilities::LEDGER_RESOURCE_URI,
                name: 'ledger_snapshot',
                title: 'Atelier Settlement Ledger Snapshot',
                description: 'Live JSON snapshot of merchant balance, orders, and settlement ledger entries.',
                mimeType: 'application/json',
            );

        if (null !== $sessionStorageDir && '' !== $sessionStorageDir) {
            $sessionsPath = rtrim($sessionStorageDir, '/\\').'/sessions';
            $locksPath = rtrim($sessionStorageDir, '/\\').'/locks';
            if (!is_dir($sessionsPath)) {
                mkdir($sessionsPath, 0775, true);
            }
            if (!is_dir($locksPath)) {
                mkdir($locksPath, 0775, true);
            }
            $builder
                ->setSession(new FileSessionStore($sessionsPath))
                ->setSessionLock(new FileSessionLock($locksPath));
        } else {
            $builder->setSession(new InMemorySessionStore());
        }

        return $builder;
    }

    /**
     * @param (\Closure(): string)|null $uiHtmlProvider
     */
    public static function createServer(
        LedgerStore $ledger,
        string $scope = 'default',
        ?string $sessionStorageDir = null,
        int $paginationLimit = 50,
        string $requestStateSigningKey = self::DEFAULT_SIGNING_KEY,
        ?\Closure $uiHtmlProvider = null,
    ): Server {
        return self::createBuilder(
            $ledger,
            $scope,
            $sessionStorageDir,
            $paginationLimit,
            $requestStateSigningKey,
            $uiHtmlProvider,
        )->build();
    }

    /**
     * Builds a dedicated modern-era (`2026-07-28`) stateless protocol dispatcher.
     *
     * @param (\Closure(): string)|null $uiHtmlProvider
     */
    public static function createStatelessProtocol(
        LedgerStore $ledger,
        string $scope = 'default',
        int $paginationLimit = 50,
        string $requestStateSigningKey = self::DEFAULT_SIGNING_KEY,
        ?\Closure $uiHtmlProvider = null,
    ): StatelessProtocol {
        return self::createBuilder(
            $ledger,
            $scope,
            null,
            $paginationLimit,
            $requestStateSigningKey,
            $uiHtmlProvider,
        )->buildStateless([ProtocolVersion::V2026_07_28]);
    }
}
