<?php

declare(strict_types=1);

namespace Faultline\Agent;

use Faultline\Chaos\ChaosScenario;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory as HuggingFaceFactory;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * Builds the Symfony AI `PlatformInterface` driving the MCP Agent loop.
 *
 * Supports zero-key deterministic offline execution (for instant `docker compose up` and CI) as well as
 * live Hugging Face Inference Providers (`symfony/ai-hugging-face-platform`) when `HF_TOKEN` is supplied.
 */
final class AgentPlatformFactory
{
    public const DEFAULT_HF_MODEL = 'Qwen/Qwen2.5-72B-Instruct';
    public const DETERMINISTIC_MODEL = 'faultline-deterministic-reasoner-v1';

    /**
     * @return array{platform: PlatformInterface, model: non-empty-string, mode: 'huggingface_live'|'deterministic_replay'}
     */
    public static function createForScenario(
        ChaosScenario $scenario,
        string $toolPrefix = 'commerce_',
        bool $preferLiveHf = false,
        ?string $hfToken = null,
    ): array {
        $resolvedToken = $hfToken ?? ($_ENV['HF_TOKEN'] ?? getenv('HF_TOKEN') ?: null);
        if ($preferLiveHf && \is_string($resolvedToken) && '' !== trim($resolvedToken)) {
            $envModel = $_ENV['HF_MODEL'] ?? getenv('HF_MODEL') ?: self::DEFAULT_HF_MODEL;
            /** @var non-empty-string $liveModel */
            $liveModel = \is_string($envModel) && '' !== trim($envModel) ? trim($envModel) : self::DEFAULT_HF_MODEL;

            return [
                'platform' => HuggingFaceFactory::createPlatform(trim($resolvedToken)),
                'model' => $liveModel,
                'mode' => 'huggingface_live',
            ];
        }

        $callSeq = 0;
        $mutationAttempts = 0;
        $maxMutationAttempts = 4;

        $platform = MockPlatformFactory::createPlatform(
            static function (Model $model, array|string|object $payload, array $options = []) use (
                $scenario,
                $toolPrefix,
                &$callSeq,
                &$mutationAttempts,
                $maxMutationAttempts,
            ): ResultInterface {
                ++$callSeq;
                $messages = self::extractMessages($payload);

                $getOrderTool = $toolPrefix.'get_order';
                $targetMutationTool = $toolPrefix.$scenario->targetTool;

                $orderInspected = false;
                $mutationSucceeded = false;
                $lastMutationReceipt = null;
                $lastMutationFailed = false;

                foreach ($messages as $msg) {
                    $role = (string) ($msg['role'] ?? '');
                    if ('tool' !== $role) {
                        continue;
                    }

                    $content = self::extractMessageText($msg);
                    $toolName = (string) ($msg['name'] ?? '');

                    if (str_contains($toolName, 'get_order') || str_contains($content, '"order_id"')) {
                        if (!str_contains($content, 'An error occurred')) {
                            $orderInspected = true;
                        }
                    }

                    if (
                        str_contains($toolName, $scenario->targetTool)
                        || str_contains($content, '"tx_id"')
                        || str_contains($content, 'executing tool "'.$targetMutationTool.'"')
                    ) {
                        if (str_contains($content, 'An error occurred')) {
                            $lastMutationFailed = true;
                        } elseif (str_contains($content, '"tx_id"')) {
                            $mutationSucceeded = true;
                            $lastMutationFailed = false;
                            $lastMutationReceipt = $content;
                        }
                    }
                }

                // Step 1: Read order state via MCP read-only tool
                if (!$orderInspected) {
                    return new ToolCallResult([
                        new ToolCall(
                            id: \sprintf('call_order_%d', $callSeq),
                            name: $getOrderTool,
                            arguments: ['orderId' => $scenario->targetOrderId],
                        ),
                    ]);
                }

                // Step 2: Execute mutating settlement tool (and retry on opaque transport error up to cap)
                if (!$mutationSucceeded && $mutationAttempts < $maxMutationAttempts) {
                    ++$mutationAttempts;
                    $args = 'capture_payment' === $scenario->targetTool
                        ? [
                            'orderId' => $scenario->targetOrderId,
                            'amountCents' => $scenario->intendedAmountCents,
                            'memo' => 'automated_settlement_'.$scenario->id,
                        ]
                        : [
                            'orderId' => $scenario->targetOrderId,
                            'amountCents' => $scenario->intendedAmountCents,
                            'reason' => 'customer_settlement_'.$scenario->id,
                        ];

                    return new ToolCallResult([
                        new ToolCall(
                            id: \sprintf('call_mut_%d_%d', $mutationAttempts, $callSeq),
                            name: $targetMutationTool,
                            arguments: $args,
                        ),
                    ]);
                }

                // Step 3: Conclude agent turn with factual summary
                if ($mutationSucceeded && null !== $lastMutationReceipt) {
                    $decoded = json_decode($lastMutationReceipt, true);
                    $txId = \is_array($decoded) ? (string) ($decoded['tx_id'] ?? 'UNKNOWN') : 'CONFIRMED';

                    return new TextResult(\sprintf(
                        'Completed %s on %s after %d attempt(s). Confirmed ledger receipt: %s.',
                        $scenario->targetTool,
                        $scenario->targetOrderId,
                        $mutationAttempts,
                        $txId,
                    ));
                }

                if (true === ($scenario->faultConfig['simulate_optimistic_hallucination'] ?? false)) {
                    return new TextResult(\sprintf(
                        'Refund of %.2f EUR for %s has been processed and queued for settlement.',
                        $scenario->intendedAmountCents / 100,
                        $scenario->targetOrderId,
                    ));
                }

                return new TextResult(\sprintf(
                    'Halted %s on %s after %d failed attempt(s) (%s).',
                    $scenario->targetTool,
                    $scenario->targetOrderId,
                    $mutationAttempts,
                    $lastMutationFailed ? 'transport fault persisted' : 'incomplete',
                ));
            },
        );

        return [
            'platform' => $platform,
            'model' => self::DETERMINISTIC_MODEL,
            'mode' => 'deterministic_replay',
        ];
    }

    /**
     * @param array<string|int, mixed>|string|object $payload
     *
     * @return list<array<string, mixed>>
     */
    private static function extractMessages(array|string|object $payload): array
    {
        if (\is_object($payload)) {
            $payload = (array) $payload;
        }
        if (!\is_array($payload)) {
            return [];
        }

        if (isset($payload['messages']) && \is_array($payload['messages'])) {
            /** @var list<array<string, mixed>> $msgs */
            $msgs = array_values(array_filter($payload['messages'], 'is_array'));

            return $msgs;
        }

        if (array_is_list($payload)) {
            /** @var list<array<string, mixed>> $msgs */
            $msgs = array_values(array_filter($payload, 'is_array'));

            return $msgs;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function extractMessageText(array $message): string
    {
        $content = $message['content'] ?? '';
        if (\is_string($content)) {
            return $content;
        }
        if (\is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (\is_string($item)) {
                    $parts[] = $item;
                } elseif (\is_array($item) && isset($item['text']) && \is_string($item['text'])) {
                    $parts[] = $item['text'];
                }
            }
            if ([] !== $parts) {
                return implode("\n", $parts);
            }

            $encoded = json_encode($content, \JSON_UNESCAPED_SLASHES);

            return false !== $encoded ? $encoded : '';
        }

        return '';
    }
}
