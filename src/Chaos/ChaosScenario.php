<?php

declare(strict_types=1);

namespace Faultline\Chaos;

/**
 * Immutable definition of a deterministic MCP chaos scenario and its expected ledger invariants.
 */
final readonly class ChaosScenario
{
    /**
     * @param array<string, mixed> $faultConfig
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $category,
        public string $summary,
        public string $targetOrderId,
        public string $targetTool,
        public int $intendedAmountCents,
        public int $expectedLedgerDeltaCents,
        public int $expectedWriteCount,
        public string $agentPrompt,
        public array $faultConfig,
        public int $paginationLimit = 50,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'summary' => $this->summary,
            'target_order_id' => $this->targetOrderId,
            'target_tool' => $this->targetTool,
            'intended_amount_cents' => $this->intendedAmountCents,
            'expected_ledger_delta_cents' => $this->expectedLedgerDeltaCents,
            'expected_write_count' => $this->expectedWriteCount,
            'agent_prompt' => $this->agentPrompt,
            'fault_config' => $this->faultConfig,
            'pagination_limit' => $this->paginationLimit,
        ];
    }
}
