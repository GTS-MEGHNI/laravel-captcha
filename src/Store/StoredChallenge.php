<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Store;

use GtsMeghni\LaravelCaptcha\Support\Value;

/**
 * The whole of what a challenge leaves in the cache.
 *
 * The encoded image is not kept: it is redrawn from `seed` when the stream
 * endpoint is hit, which holds a cache entry to a couple of hundred bytes.
 * That matters when the cache is a database table.
 *
 * `glyphs` is what gets drawn, so it has to be stored to redraw the same image
 * on a refetch. For a text challenge the glyphs are the answer, so treat this
 * entry as server-side secret material; it is unreadable by the client and
 * lives for the configured TTL only. The answer is compared through `digest`
 * rather than the glyphs, so a generator whose answer differs from what is drawn
 * never stores that answer in the clear.
 */
class StoredChallenge
{
    /**
     * @param  non-empty-list<string>  $glyphs
     */
    final public function __construct(
        public readonly string $preset,
        public readonly int $seed,
        public readonly array $glyphs,
        public readonly string $digest,
        public readonly float $issuedAt = 0.0,
        public readonly ?string $ip = null,
    ) {}

    /**
     * @return array{preset: string, seed: int, glyphs: non-empty-list<string>, digest: string, issued_at: float, ip: string|null}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'seed' => $this->seed,
            'glyphs' => $this->glyphs,
            'digest' => $this->digest,
            'issued_at' => $this->issuedAt,
            'ip' => $this->ip,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (! isset($payload['preset'], $payload['seed'], $payload['digest'])) {
            return null;
        }

        $glyphs = Value::strings($payload['glyphs'] ?? null);

        if ($glyphs === []) {
            return null;
        }

        return new self(
            preset: Value::string($payload['preset']),
            seed: Value::int($payload['seed']),
            glyphs: $glyphs,
            digest: Value::string($payload['digest']),
            issuedAt: Value::float($payload['issued_at'] ?? null),
            ip: Value::nullableString($payload['ip'] ?? null),
        );
    }
}
