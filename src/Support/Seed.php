<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Support;

/**
 * A deterministic pseudo-random source for everything visual.
 *
 * The image is drawn when it is requested rather than when the challenge is
 * issued, so only the seed travels through the cache. Replaying a seed redraws
 * the same image, which keeps a refetch stable and makes the renderer testable.
 *
 * This is a splitmix32 sequence: not cryptographic, and never used to pick an
 * answer. Answers come from random_int(); see the challenge generators.
 */
class Seed
{
    private int $state;

    public function __construct(public readonly int $value)
    {
        $this->state = $value & 0xFFFFFFFF;
    }

    /**
     * A fresh, unpredictable seed for a new challenge.
     */
    public static function random(): self
    {
        return new self(random_int(0, 0xFFFFFFFF));
    }

    /**
     * The next value in the sequence, as an integer in [$min, $max].
     */
    public function between(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        return $min + (int) ($this->next() % (($max - $min) + 1));
    }

    /**
     * One element of the given list.
     *
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $values
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        return $values[$this->between(0, count($values) - 1)];
    }

    /**
     * The next value in the sequence, as a float in [0, 1).
     */
    public function fraction(): float
    {
        return $this->next() / 4294967296;
    }

    private function next(): int
    {
        $this->state = ($this->state + 0x9E3779B9) & 0xFFFFFFFF;

        $z = $this->state;
        $z = $this->multiply($z ^ ($z >> 16), 0x85EBCA6B);
        $z = $this->multiply($z ^ ($z >> 13), 0xC2B2AE35);

        return $z ^ ($z >> 16);
    }

    /**
     * Multiply two 32-bit values, keeping the low 32 bits.
     *
     * Done in 16-bit halves because the full product of two 32-bit operands
     * reaches 2^64, past PHP_INT_MAX. PHP turns that overflow into a float,
     * and a float carries 53 bits of mantissa, so the low bits this sequence
     * is built from are the first thing lost. Splitting the operand keeps
     * every partial product under 2^48, where the arithmetic stays exact.
     */
    private function multiply(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;

        $low = ($a & 0xFFFF) * $b;
        $high = (($a >> 16) & 0xFFFF) * $b;

        return ($low + (($high & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
