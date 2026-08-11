<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Store;

/**
 * What one prune pass did.
 */
class PruneResult
{
    final public function __construct(
        public readonly string $driver,
        public readonly int $removed,
        public readonly bool $supported,
        public readonly bool $scoped,
    ) {}

    /**
     * A store that evicts expired entries by itself, so there is nothing to do.
     */
    public static function unnecessary(string $driver): self
    {
        return new self($driver, 0, false, true);
    }
}
