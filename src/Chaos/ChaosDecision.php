<?php

declare(strict_types=1);

namespace Faultline\Chaos;

/**
 * Value object representing a deterministic pre-commit or post-commit fault action.
 */
final readonly class ChaosDecision
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $meta
     */
    public function __construct(
        public string $action,
        public ?string $faultCode = null,
        public int $httpStatus = 200,
        public array $headers = [],
        public int $simulatedLatencyMs = 8,
        public ?int $truncateBytes = null,
        public ?string $overrideBody = null,
        public array $meta = [],
    ) {
    }

    public function isFault(): bool
    {
        return null !== $this->faultCode;
    }

    public static function passthrough(int $latencyMs = 8): self
    {
        return new self(
            action: 'PASSTHROUGH',
            faultCode: null,
            httpStatus: 200,
            simulatedLatencyMs: $latencyMs,
        );
    }
}
