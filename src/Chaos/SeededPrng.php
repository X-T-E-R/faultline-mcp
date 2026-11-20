<?php

declare(strict_types=1);

namespace Faultline\Chaos;

/**
 * Platform-independent deterministic PRNG & coordinate hash for reproducible fault injection.
 *
 * Uses SHA-256 coordinate mixing so identical `(seed, occurrence, channel)` tuples produce bit-identical
 * schedules across Windows, Linux, STDIO subprocesses, and stateless HTTP worker processes.
 */
final class SeededPrng
{
    private int $cursor = 0;

    public function __construct(
        private readonly int $seed,
    ) {
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function nextUint32(string $channel = 'default'): int
    {
        return self::uint32At($this->seed, $this->cursor++, $channel);
    }

    public function nextFloat(string $channel = 'default'): float
    {
        return self::unitIntervalAt($this->seed, $this->cursor++, $channel);
    }

    public function nextIntBetween(int $min, int $max, string $channel = 'default'): int
    {
        return self::intBetweenAt($this->seed, $this->cursor++, $min, $max, $channel);
    }

    public static function uint32At(int $seed, int $occurrence, string $channel = 'default'): int
    {
        $digest = hash('sha256', \sprintf('faultline:v1:%d:%d:%s', $seed, $occurrence, $channel), true);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($digest, 0, 4));

        return $unpacked[1];
    }

    /**
     * Deterministic float in [0.0, 1.0).
     */
    public static function unitIntervalAt(int $seed, int $occurrence, string $channel = 'default'): float
    {
        $u32 = self::uint32At($seed, $occurrence, $channel);

        return $u32 / 4_294_967_296.0;
    }

    public static function intBetweenAt(int $seed, int $occurrence, int $min, int $max, string $channel = 'default'): int
    {
        if ($max <= $min) {
            return $min;
        }

        $span = $max - $min + 1;
        $u32 = self::uint32At($seed, $occurrence, $channel);

        return $min + ($u32 % $span);
    }

    /**
     * Canonicalizes any JSON-compatible value (recursively sorting associative keys) and returns its SHA-256 hex digest.
     */
    public static function canonicalSha256(mixed $value): string
    {
        $normalized = self::normalize($value);
        $encoded = json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return hash('sha256', false !== $encoded ? $encoded : '');
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        } elseif ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (!\is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);
        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
