<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Store;

use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Keeps issued challenges in the cache and hands out their tokens.
 *
 * A token is a random 40-character string, which makes it unguessable on its
 * own; there is no signature to verify and nothing about the answer travels to
 * the client. The store must be shared by every worker serving traffic, or an
 * image URL issued by one container will not resolve on another.
 */
class ChallengeStore
{
    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected string $prefix = 'captcha',
        protected int $ttl = 120,
    ) {}

    /**
     * Store a challenge and return the token that addresses it.
     *
     * @param  non-empty-list<string>  $glyphs
     */
    public function put(string $preset, int $seed, array $glyphs, string $answer, ?string $ip = null): string
    {
        $token = Str::random(40);

        $this->repository()->put(
            $this->key($token),
            (new StoredChallenge(
                $preset,
                $seed,
                $glyphs,
                $this->digest($answer),
                // Carbon rather than microtime so the clock can be travelled in
                // tests and frozen by an application that wants to.
                Carbon::now()->getTimestampMs() / 1000,
                $ip,
            ))->toArray(),
            $this->ttl,
        );

        return $token;
    }

    /**
     * Read a challenge without consuming it, for drawing the image.
     */
    public function find(string $token): ?StoredChallenge
    {
        return StoredChallenge::fromArray(
            Value::array($this->repository()->get($this->key($token))),
        );
    }

    /**
     * Read a challenge and consume it, so one token allows one attempt.
     *
     * Taken under a lock, because Laravel's `pull()` is a `get()` followed by a
     * `forget()` rather than one atomic operation. Two verifications arriving at
     * the same instant would otherwise both read the challenge before either
     * deleted it, and a single solved captcha would authorise both requests.
     *
     * A caller that cannot take the lock is treated as having lost the race and
     * gets nothing, which fails its verification. That is the safe direction:
     * the cost is a rejected duplicate submission, not an accepted one.
     */
    public function pull(string $token): ?StoredChallenge
    {
        $store = $this->repository()->getStore();

        if (! $store instanceof LockProvider) {
            return $this->take($token);
        }

        $taken = $store->lock($this->key($token).':lock', 5)->get(
            fn (): ?StoredChallenge => $this->take($token),
        );

        return $taken instanceof StoredChallenge ? $taken : null;
    }

    /**
     * Read and delete the challenge behind a token.
     */
    protected function take(string $token): ?StoredChallenge
    {
        return StoredChallenge::fromArray(
            Value::array($this->repository()->pull($this->key($token))),
        );
    }

    public function forget(string $token): void
    {
        $this->repository()->forget($this->key($token));
    }

    /**
     * Compare a submitted answer against a stored digest in constant time.
     */
    public function matches(StoredChallenge $challenge, string $answer): bool
    {
        return hash_equals($challenge->digest, $this->digest($answer));
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    protected function digest(string $answer): string
    {
        return hash('sha256', $answer);
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
