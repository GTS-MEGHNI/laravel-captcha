<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha;

use GtsMeghni\LaravelCaptcha\Challenges\ChallengeManager;
use GtsMeghni\LaravelCaptcha\Rendering\Renderer;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use GtsMeghni\LaravelCaptcha\Support\Escalation;
use GtsMeghni\LaravelCaptcha\Support\Preset;
use GtsMeghni\LaravelCaptcha\Support\Seed;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The package entry point: issue a challenge, draw it, check an answer.
 */
class Captcha
{
    public function __construct(
        protected Config $config,
        protected ChallengeManager $challenges,
        protected Renderer $renderer,
        protected ChallengeStore $store,
        protected UrlGenerator $url,
        protected Escalation $escalation,
    ) {}

    /**
     * Issue a challenge and return its token and image URL.
     *
     * No drawing happens here. A challenge that is issued but never displayed
     * costs one small cache write and nothing else.
     */
    public function create(string $preset = 'default', ?string $ip = null): IssuedChallenge
    {
        $resolved = $this->preset($preset);

        $challenge = $this->challenges->generate($resolved);
        $seed = Seed::random();

        $token = $this->store->put(
            $resolved->name,
            $seed->value,
            $challenge->glyphs,
            $challenge->answer,
            $ip,
        );

        return new IssuedChallenge(
            token: $token,
            url: $this->imageUrl($token),
            expiresIn: $this->store->ttl(),
            expiresAt: Carbon::now()->addSeconds($this->store->ttl()),
            preset: $resolved->name,
        );
    }

    /**
     * Draw the image for an issued token, or null once it has expired.
     *
     * Redrawing from the stored seed is deterministic, so a client that
     * refetches the URL receives the bytes it already has.
     */
    public function image(string $token): ?string
    {
        $stored = $this->store->find($token);

        if (! $stored instanceof Store\StoredChallenge) {
            return null;
        }

        return $this->renderer->render(
            new Challenges\Challenge($stored->glyphs, ''),
            $this->preset($stored->preset),
            new Seed($stored->seed),
        );
    }

    /**
     * Check an answer, consuming the challenge either way.
     *
     * A wrong answer burns the token on purpose: without that, one issued
     * challenge would allow unlimited guesses.
     */
    public function verify(?string $answer, ?string $token, ?string $ip = null): bool
    {
        if ($token === null || $token === '' || $answer === null) {
            return false;
        }

        $stored = $this->store->pull($token);

        if (! $stored instanceof Store\StoredChallenge) {
            return false;
        }

        if (! $this->answeredPlausibly($stored)) {
            return false;
        }

        if (! $this->cameFromTheSamePlace($stored, $ip)) {
            return false;
        }

        $preset = $this->preset($stored->preset);

        return $this->store->matches(
            $stored,
            $preset->sensitive ? $answer : Str::lower($answer),
        );
    }

    /**
     * Reject an answer that arrived faster than a person could give it.
     *
     * Reading six distorted characters and typing them takes a human at least a
     * second or so; a script answers in tens of milliseconds. This does not stop
     * a script that waits on purpose — it costs nothing and removes the ones that
     * do not bother.
     */
    protected function answeredPlausibly(Store\StoredChallenge $stored): bool
    {
        $minimum = Value::float($this->config->get('captcha.min_seconds'), 0.0);

        if ($minimum <= 0.0 || $stored->issuedAt <= 0.0) {
            return true;
        }

        return ((Carbon::now()->getTimestampMs() / 1000) - $stored->issuedAt) >= $minimum;
    }

    /**
     * Optionally require the answer to come from the address that asked.
     *
     * Without this, a token issued to a visitor can be solved anywhere — which is
     * exactly how a solving service works: the page forwards the image out, and
     * the answer comes back from somewhere else.
     *
     * Off by default, because a phone that switches from wifi to mobile data mid
     * form changes address and would be refused for no fault of its own.
     */
    protected function cameFromTheSamePlace(Store\StoredChallenge $stored, ?string $ip): bool
    {
        if (! Value::bool($this->config->get('captcha.bind_ip'), false)) {
            return true;
        }

        // Fail closed: if binding is demanded, an unknown address is a mismatch.
        return $stored->ip !== null && $ip !== null && hash_equals($stored->ip, $ip);
    }

    /**
     * Whether answers are being enforced.
     *
     * Generation keeps working when disabled, so a front-end can be developed
     * against a staging environment that accepts anything.
     */
    public function enabled(): bool
    {
        return Value::bool($this->config->get('captcha.enabled'), true);
    }

    public function preset(string $name = 'default'): Preset
    {
        return Preset::resolve(Value::map($this->config->get('captcha')), $name);
    }

    public function challenges(): ChallengeManager
    {
        return $this->challenges;
    }

    /**
     * Whether this key has failed often enough to deserve a challenge.
     *
     * Lets a form stay clean until there is a reason not to:
     *
     *     if (Captcha::requiredFor('login:'.$email)) {
     *         $rules['captcha'] = ['required', new Captcha];
     *     }
     */
    public function requiredFor(string $key, ?int $after = null): bool
    {
        return $this->escalation->required($key, $after);
    }

    /**
     * Record a failure against a key and return the running count.
     */
    public function recordFailure(string $key): int
    {
        return $this->escalation->record($key);
    }

    /**
     * Forget a key's failures, once it has succeeded.
     */
    public function clearFailures(string $key): void
    {
        $this->escalation->clear($key);
    }

    public function escalation(): Escalation
    {
        return $this->escalation;
    }

    protected function imageUrl(string $token): string
    {
        $name = Value::string($this->config->get('captcha.routes.name'), 'captcha');

        if (Value::bool($this->config->get('captcha.routes.enabled'), true)) {
            return $this->url->route($name.'.image', ['token' => $token]);
        }

        $prefix = trim(Value::string($this->config->get('captcha.routes.prefix'), 'captcha'), '/');

        return $this->url->to($prefix.'/'.$token.'.png');
    }
}
