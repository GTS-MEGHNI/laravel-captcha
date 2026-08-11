<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Pow;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * A proof-of-work challenge, as the client receives it.
 *
 * There is nothing here to look at, so nothing a vision model can read. The
 * client has to spend CPU: find a nonce whose hash starts with `difficulty`
 * zero bits. Verification is a single hash.
 *
 * @implements Arrayable<string, mixed>
 */
class PowChallenge implements Arrayable
{
    final public function __construct(
        public readonly string $token,
        public readonly string $salt,
        public readonly int $difficulty,
        public readonly int $expiresIn,
        public readonly Carbon $expiresAt,
    ) {}

    /**
     * @return array{token: string, salt: string, difficulty: int, algorithm: string, expires_in: int, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'salt' => $this->salt,
            'difficulty' => $this->difficulty,
            'algorithm' => 'sha256',
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
