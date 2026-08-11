<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Store;

use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Single-use tokens backed by the cache.
 *
 * Shared by the image challenges and the proof of work, because both need the
 * same guarantee: a token addresses a server-held payload, is unguessable on its
 * own, expires on a clock, and can be spent exactly once.
 */
class TokenStore
{
    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected string $prefix = 'captcha',
        protected int $ttl = 120,
    ) {}

    /**
     * Store a payload and return the token that addresses it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function put(array $payload): string
    {
        $token = Str::random(40);

        $this->repository()->put($this->key($token), $payload, $this->ttl);

        return $token;
    }

    /**
     * Read a payload without spending it.
     *
     * @return array<array-key, mixed>
     */
    public function find(string $token): array
    {
        return Value::array($this->repository()->get($this->key($token)));
    }

    /**
     * Read a payload and spend it, so one token allows one attempt.
     *
     * Taken under a lock, because Laravel's `pull()` is a `get()` followed by a
     * `forget()` rather than one atomic operation. Two requests arriving at the
     * same instant would otherwise both read the payload before either deleted
     * it, and one solved challenge would authorise both.
     *
     * A caller that cannot take the lock is treated as having lost the race and
     * gets nothing, which fails its verification. That is the safe direction:
     * the cost is a rejected duplicate submission, not an accepted one.
     *
     * @return array<array-key, mixed>
     */
    public function pull(string $token): array
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            return $this->take($token);
        }

        $taken = $store->lock($this->key($token).':lock', 5)->get(
            fn (): array => $this->take($token),
        );

        return is_array($taken) ? $taken : [];
    }

    public function forget(string $token): void
    {
        $this->repository()->forget($this->key($token));
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function take(string $token): array
    {
        return Value::array($this->repository()->pull($this->key($token)));
    }

    protected function key(string $token): string
    {
        return $this->prefix.':'.$token;
    }

    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
