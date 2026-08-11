<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Support;

/**
 * Narrows the mixed values that come out of config and cache.
 *
 * Everything read from `config()` or a cache payload is `mixed`, and PHP's casts
 * are quietly forgiving in the wrong direction: `(string) []` is `"Array"` plus a
 * warning, `(int) 'abc'` is `0`, and `(float) null` is `0.0`. Each method here
 * accepts only what can be meaningfully converted and falls back to a stated
 * default otherwise, so a malformed config value produces documented behaviour
 * rather than a surprise.
 */
final class Value
{
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * A string, or null when the value is absent rather than merely empty.
     */
    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = self::string($value);

        return $string === '' ? null : $string;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Only the string-keyed entries.
     *
     * Config groups are keyed by name, but an array read out of config could
     * carry integer keys. Dropping them earns the narrower array<string, mixed>
     * type rather than asserting it.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $map = [];

        foreach (self::array($value) as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * Every element converted to a string, reindexed.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        $strings = [];

        foreach (self::array($value) as $item) {
            $string = self::string($item);

            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return $strings;
    }
}
