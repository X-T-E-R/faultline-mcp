<?php

declare(strict_types=1);

namespace Faultline\TargetServer;

use Faultline\Ledger\LedgerStore;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Capability\Attribute\McpResource;
use Mcp\Server\Capability\Attribute\McpTool;

/**
 * Reference e-commerce order, payment capture, and refund capabilities exposed via Official PHP MCP SDK.
 */
final class CommerceMcpCapabilities
{
    public const UI_RESOURCE_URI = 'ui://faultline/oscilloscope';
    public const LEDGER_RESOURCE_URI = 'ledger://atelier/snapshot';

    /**
     * @param (\Closure(): string)|null $uiHtmlProvider
     */
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly string $scope = 'default',
        private readonly ?\Closure $uiHtmlProvider = null,
    ) {
    }

    /**
     * Fetch current settlement and refund status for a customer order ID (e.g. ORD-9042).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_order',
        title: 'Inspect Order Settlement',
        description: 'Reads the current authorization, capture, and refund balances in cents for an e-commerce order.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    public function getOrder(string $orderId): array
    {
        return $this->ledger->getOrder($orderId, $this->scope);
    }

    /**
     * Issue a settlement refund in integer cents against a captured customer order.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'issue_refund',
        title: 'Issue Customer Refund',
        description: 'Debits the merchant settlement account and refunds integer cents (amountCents) to the customer order.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        ),
    )]
    public function issueRefund(
        string $orderId,
        int $amountCents,
        string $reason,
        ?string $idempotencyKey = null,
    ): array {
        return $this->ledger->issueRefund($orderId, $amountCents, $reason, $idempotencyKey, $this->scope);
    }

    /**
     * Capture authorized funds in integer cents for an e-commerce order.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'capture_payment',
        title: 'Capture Order Payment',
        description: 'Captures authorized integer cents (amountCents) on an order and credits the merchant ledger.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        ),
    )]
    public function capturePayment(
        string $orderId,
        int $amountCents,
        string $memo,
        ?string $idempotencyKey = null,
    ): array {
        return $this->ledger->capturePayment($orderId, $amountCents, $memo, $idempotencyKey, $this->scope);
    }

    /**
     * List immutable settlement ledger entries for auditing side effects.
     *
     * @return array{scope: string, count: int, entries: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'list_ledger_entries',
        title: 'List Settlement Ledger Entries',
        description: 'Lists immutable double-entry settlement rows, optionally filtered by orderId.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    public function listLedgerEntries(?string $orderId = null): array
    {
        $entries = $this->ledger->listEntries($orderId, $this->scope);

        return [
            'scope' => $this->scope,
            'count' => \count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * Launch the interactive Faultline Oscilloscope & Ledger Diff Inspector MCP App view.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
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
                resourceUri: self::UI_RESOURCE_URI,
                visibility: [ToolVisibility::Model, ToolVisibility::App],
            ),
        ],
    )]
    public function openFaultlineOscilloscope(?string $scenario = null): array
    {
        $snapshot = $this->ledger->snapshot($this->scope);

        return [
            'ui_resource' => self::UI_RESOURCE_URI,
            'active_scope' => $this->scope,
            'selected_scenario' => $scenario ?? 'post_commit_truncation',
            'merchant_balance_cents' => $snapshot['merchant_balance_cents'],
            'entry_count' => \count($snapshot['entries']),
        ];
    }

    #[McpResource(
        uri: self::UI_RESOURCE_URI,
        name: 'faultline_oscilloscope',
        title: 'Faultline MCP Oscilloscope & Trace Diff Inspector',
        description: 'Interactive MCP Apps (io.modelcontextprotocol/ui) cockpit displaying JSON-RPC wire frames and ledger diffs.',
        mimeType: McpApps::MIME_TYPE,
        meta: ['ui' => new \stdClass()],
    )]
    public function renderOscilloscopeApp(): TextResourceContents
    {
        $html = null !== $this->uiHtmlProvider
            ? ($this->uiHtmlProvider)()
            : self::defaultOscilloscopeHtml();

        return new TextResourceContents(
            uri: self::UI_RESOURCE_URI,
            mimeType: McpApps::MIME_TYPE,
            text: $html,
            meta: [
                'ui' => new UiResourceContentMeta(
                    csp: new UiResourceCsp(),
                    prefersBorder: true,
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpResource(
        uri: self::LEDGER_RESOURCE_URI,
        name: 'ledger_snapshot',
        title: 'Atelier Settlement Ledger Snapshot',
        description: 'Live JSON snapshot of merchant balance, orders, and settlement ledger entries.',
        mimeType: 'application/json',
    )]
    public function readLedgerSnapshot(): array
    {
        return $this->ledger->snapshot($this->scope);
    }

    public static function defaultOscilloscopeHtml(): string
    {
        $projectRoot = \dirname(__DIR__, 2);
        if (is_file($projectRoot.'/templates/oscilloscope.html')) {
            return \Faultline\Http\UiAppBundleBuilder::buildSelfContainedMcpApp($projectRoot);
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Faultline Oscilloscope</title></head><body><main>Faultline Oscilloscope Ready</main></body></html>';
    }
}
