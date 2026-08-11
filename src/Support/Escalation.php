<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Tracks failures so a challenge can be demanded only once there is a reason to.
 *
 * A captcha on every login taxes every legitimate user daily to inconvenience a
 * bot that a vision model already defeats. Asking for one after a couple of
 * failures moves that cost onto whoever is failing: a person who mistyped their
 * password sees it once, and a credential-stuffing script — wrong nearly every
 * time — sees it on every account it touches.
 *
 * The key is the caller's to choose, and it decides what is being protected.
 * Prefer something tied to the identity under attack rather than the address it
 * comes from: an office behind one NAT shares an IP, and a botnet does not.
 */
class Escalation
{
    public function __construct(
        protected RateLimiter $limiter,
        protected Config $config,
    ) {}

    /**
     * Count a challenge handed to this key, and return the running total.
     *
     * Failures are not the only signal worth pricing. A script harvesting
     * challenges to find one it can read never fails — it simply asks again — so
     * the number of challenges requested has to cost something too.
     */
    public function observe(string $key): int
    {
        return $this->limiter->hit($this->key($key, 'demands'), $this->decay());
    }

    public function demands(string $key): int
    {
        return Value::int($this->limiter->attempts($this->key($key, 'demands')));
    }

    /**
     * Whether this key has failed often enough to be challenged.
     */
    public function required(string $key, ?int $after = null): bool
    {
        return $this->attempts($key) >= ($after ?? $this->threshold());
    }

    /**
     * Record a failure and return the running count.
     */
    public function record(string $key): int
    {
        return $this->limiter->hit($this->key($key, 'failures'), $this->decay());
    }

    /**
     * Forget a key's failures, once it has succeeded.
     */
    /**
     * Forget both counters for a key, once it has succeeded.
     */
    public function clear(string $key): void
    {
        $this->limiter->clear($this->key($key, 'failures'));
        $this->limiter->clear($this->key($key, 'demands'));
    }

    public function attempts(string $key): int
    {
        return Value::int($this->limiter->attempts($this->key($key, 'failures')));
    }

    /**
     * How many failures are tolerated before a challenge is demanded.
     */
    public function threshold(): int
    {
        return max(0, Value::int($this->config->get('captcha.escalation.after'), 2));
    }

    /**
     * How long a failure is remembered, in seconds.
     */
    public function decay(): int
    {
        return max(1, Value::int($this->config->get('captcha.escalation.decay'), 900));
    }

    /**
     * Namespaced so the counter cannot collide with the application's own
     * limiters, and so `captcha:prune` can recognise it.
     *
     * The caller's key is hashed because it usually carries an email address or
     * an account id, and cache keys end up in logs, slow-query output and
     * dashboards. Truncated to 40 characters to keep keys short; a collision
     * would merely share a failure counter, so preimage resistance is not the
     * property being relied on here.
     */
    protected function key(string $key, string $counter): string
    {
        return Value::string($this->config->get('captcha.cache.prefix'), 'captcha')
            .':'.$counter.':'
            .substr(hash('sha256', $key), 0, 40);
    }
}
