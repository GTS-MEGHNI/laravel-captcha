<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * What a client needs to display and later answer a challenge.
 *
 * The image is addressed by URL rather than inlined as a data URI, so a page
 * served under a `img-src 'self'` content security policy can render it.
 *
 * @implements Arrayable<string, mixed>
 */
class IssuedChallenge implements Arrayable
{
    final public function __construct(
        public readonly string $token,
        public readonly string $url,
        public readonly int $expiresIn,
        public readonly Carbon $expiresAt,
        public readonly string $preset,
    ) {}

    /**
     * `expires_in` is a count of seconds, as in RFC 6749. `expires_at` is the
     * same moment as an ISO-8601 stamp, so a client need not assume the unit —
     * prefer `expires_in` for a countdown, since it cannot be thrown off by a
     * clock that disagrees with the server's.
     *
     * @return array{token: string, url: string, expires_in: int, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'url' => $this->url,
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
