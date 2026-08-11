<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Pow;

use GtsMeghni\LaravelCaptcha\Store\TokenStore;
use GtsMeghni\LaravelCaptcha\Support\Escalation;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * Issues and verifies proof-of-work challenges.
 *
 * The point is not to tell a human from a script — it is to charge for every
 * attempt. A client must find a nonce where sha256(salt + nonce) begins with a
 * run of zero bits; the server confirms it with one hash. Bulk abuse then costs
 * CPU in proportion to volume, which is the part that makes it uneconomic.
 *
 * What it does not do: stop a determined attacker aimed at one account. Pair it
 * with per-identity attempt limits for that.
 */
class Pow
{
    public function __construct(
        protected Config $config,
        protected TokenStore $store,
        protected Escalation $escalation,
    ) {}

    /**
     * Issue a challenge with a server-chosen salt.
     *
     * The salt never comes from the client, so a solution cannot be precomputed,
     * and the token is single-use, so it cannot be replayed.
     */
    public function create(?string $key = null, ?string $ip = null): PowChallenge
    {
        if ($key !== null && $key !== '') {
            // Count the request before pricing it, so the cost of harvesting
            // challenges rises for the client doing the harvesting.
            $this->escalation->observe($key);
        }

        $difficulty = $this->difficultyFor($key);

        // 128 bits, rendered as the 32 hex characters the client expects.
        //
        // Built from random_int() rather than bin2hex(random_bytes(16)) because
        // on the oldest supported dependency set symfony/polyfill-uuid pulls in
        // paragonie/random_compat, whose fallback random_bytes() is declared
        // without a return type; static analysis then reads every salt as mixed.
        // Both draw on the same CSPRNG, and random_int() is annotated there.
        $salt = sprintf(
            '%08x%08x%08x%08x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFFFFFF),
        );

        $token = $this->store->put([
            'salt' => $salt,
            'difficulty' => $difficulty,
            'ip' => $ip,
        ]);

        return new PowChallenge(
            token: $token,
            salt: $salt,
            difficulty: $difficulty,
            expiresIn: $this->store->ttl(),
            expiresAt: Carbon::now()->addSeconds($this->store->ttl()),
        );
    }

    /**
     * Spend a token and check the nonce that came with it.
     *
     * The challenge is consumed whether or not the nonce is right, so a token
     * buys one attempt.
     */
    public function verify(?string $nonce, ?string $token, ?string $ip = null): bool
    {
        if ($token === null || $token === '' || $nonce === null || $nonce === '') {
            return false;
        }

        // A nonce is a counter, so anything long or non-numeric is not a
        // candidate — reject it before hashing.
        if (strlen($nonce) > 24 || ! ctype_digit($nonce)) {
            return false;
        }

        $payload = $this->store->pull($token);

        if ($payload === []) {
            return false;
        }

        $salt = Value::string($payload['salt'] ?? null);
        $difficulty = Value::int($payload['difficulty'] ?? null);

        if ($salt === '' || $difficulty < 1) {
            return false;
        }

        // Optionally require the work to come back from the address that asked
        // for it, so a challenge cannot be farmed out to be solved elsewhere.
        if (Value::bool($this->config->get('captcha.bind_ip'), false)) {
            $issuedTo = Value::nullableString($payload['ip'] ?? null);

            if ($issuedTo === null || $ip === null || ! hash_equals($issuedTo, $ip)) {
                return false;
            }
        }

        return $this->leadingZeroBits(hash('sha256', $salt.$nonce)) >= $difficulty;
    }

    public function enabled(): bool
    {
        return Value::bool($this->config->get('captcha.pow.enabled'), true);
    }

    /**
     * The floor every client pays.
     */
    public function difficulty(): int
    {
        return $this->clamp(Value::int($this->config->get('captcha.pow.difficulty'), 16));
    }

    /**
     * The floor plus whatever this key's failures have earned.
     *
     * Passing no key means no history to price, so the floor applies. The key
     * should identify what is under attack — an account, an address — and is the
     * caller's choice; the controller uses the request IP by default.
     */
    public function difficultyFor(?string $key): int
    {
        $difficulty = $this->difficulty();

        if ($key === null || $key === '' || ! Value::bool($this->config->get('captcha.pow.escalate'), true)) {
            return $difficulty;
        }

        $step = max(0, Value::int($this->config->get('captcha.pow.step'), 2));
        $ceiling = $this->clamp(Value::int($this->config->get('captcha.pow.max_difficulty'), 24));

        $added = $step * $this->escalation->attempts($key);

        // Every `every` challenges requested adds a step as well, so a client
        // that keeps asking pays more whether or not it ever submits.
        $every = max(1, Value::int($this->config->get('captcha.pow.volume.every'), 20));
        $volumeStep = max(0, Value::int($this->config->get('captcha.pow.volume.step'), 2));

        $added += $volumeStep * intdiv($this->escalation->demands($key), $every);

        return min($ceiling, $difficulty + $added);
    }

    /**
     * Keep difficulty inside a range that is solvable and worth solving.
     *
     * Below 1 there is no work; above 28 a phone stops being usable at all.
     */
    protected function clamp(int $difficulty): int
    {
        return max(1, min(28, $difficulty));
    }

    /**
     * How many zero bits a hex digest opens with.
     */
    public function leadingZeroBits(string $digest): int
    {
        $bits = 0;

        foreach (str_split($digest) as $character) {
            $nibble = (int) hexdec($character);

            if ($nibble === 0) {
                $bits += 4;

                continue;
            }

            return $bits + match (true) {
                $nibble < 2 => 3,
                $nibble < 4 => 2,
                $nibble < 8 => 1,
                default => 0,
            };
        }

        return $bits;
    }
}
