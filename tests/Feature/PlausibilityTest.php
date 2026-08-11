<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Pow\Pow;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use GtsMeghni\LaravelCaptcha\Support\Escalation;

function answerOf(string $token): string
{
    return implode('', app(ChallengeStore::class)->find($token)->glyphs);
}

describe('minimum answer time', function () {
    it('refuses an answer that arrives faster than a person could give it', function () {
        config()->set('captcha.min_seconds', 1.5);

        $captcha = app(Captcha::class);
        $issued = $captcha->create();

        expect($captcha->verify(answerOf($issued->token), $issued->token))->toBeFalse();
    });

    it('accepts the same answer once enough time has passed', function () {
        config()->set('captcha.min_seconds', 1.5);

        $captcha = app(Captcha::class);
        $issued = $captcha->create();
        $answer = answerOf($issued->token);

        $this->travel(2)->seconds();

        expect($captcha->verify($answer, $issued->token))->toBeTrue();
    });

    it('accepts any timing when the floor is zero', function () {
        config()->set('captcha.min_seconds', 0);

        $captcha = app(Captcha::class);
        $issued = $captcha->create();

        expect($captcha->verify(answerOf($issued->token), $issued->token))->toBeTrue();
    });

    it('does not apply to the proof of work, where a fast solve is honest', function () {
        config()->set('captcha.min_seconds', 5);
        config()->set('captcha.pow.difficulty', 8);

        $pow = app(Pow::class);
        $challenge = $pow->create();

        for ($nonce = 0; ; $nonce++) {
            if ($pow->leadingZeroBits(hash('sha256', $challenge->salt.$nonce)) >= 8) {
                break;
            }
        }

        expect($pow->verify((string) $nonce, $challenge->token))->toBeTrue();
    });
});

describe('binding a challenge to its requester', function () {
    it('ignores the address when binding is off', function () {
        config()->set('captcha.bind_ip', false);

        $captcha = app(Captcha::class);
        $issued = $captcha->create('default', '10.0.0.1');

        expect($captcha->verify(answerOf($issued->token), $issued->token, '203.0.113.9'))->toBeTrue();
    });

    it('accepts the same address and refuses another', function () {
        config()->set('captcha.bind_ip', true);

        $captcha = app(Captcha::class);

        $same = $captcha->create('default', '10.0.0.1');
        expect($captcha->verify(answerOf($same->token), $same->token, '10.0.0.1'))->toBeTrue();

        $other = $captcha->create('default', '10.0.0.1');
        expect($captcha->verify(answerOf($other->token), $other->token, '203.0.113.9'))->toBeFalse();
    });

    it('fails closed when binding is demanded but no address is known', function () {
        config()->set('captcha.bind_ip', true);

        $captcha = app(Captcha::class);

        $noneStored = $captcha->create();
        expect($captcha->verify(answerOf($noneStored->token), $noneStored->token, '10.0.0.1'))->toBeFalse();

        $noneGiven = $captcha->create('default', '10.0.0.1');
        expect($captcha->verify(answerOf($noneGiven->token), $noneGiven->token))->toBeFalse();
    });

    it('binds the proof of work too', function () {
        config()->set('captcha.bind_ip', true);
        config()->set('captcha.pow.difficulty', 8);

        $pow = app(Pow::class);
        $challenge = $pow->create(null, '10.0.0.1');

        for ($nonce = 0; ; $nonce++) {
            if ($pow->leadingZeroBits(hash('sha256', $challenge->salt.$nonce)) >= 8) {
                break;
            }
        }

        expect($pow->verify((string) $nonce, $challenge->token, '203.0.113.9'))->toBeFalse();
    });
});

describe('pricing requests as well as failures', function () {
    it('raises difficulty for a client that keeps asking', function () {
        config()->set('captcha.pow.difficulty', 16);
        config()->set('captcha.pow.volume', ['every' => 5, 'step' => 2]);

        $pow = app(Pow::class);

        // Five requests earn one step, ten earn two.
        expect($pow->create('harvester')->difficulty)->toBe(16);

        foreach (range(1, 4) as $ignored) {
            $pow->create('harvester');
        }

        expect($pow->create('harvester')->difficulty)->toBe(18);

        foreach (range(1, 4) as $ignored) {
            $pow->create('harvester');
        }

        expect($pow->create('harvester')->difficulty)->toBe(20);
    });

    it('counts requests separately from failures and adds both', function () {
        config()->set('captcha.pow.difficulty', 16);
        config()->set('captcha.pow.step', 2);
        config()->set('captcha.pow.volume', ['every' => 5, 'step' => 2]);

        $pow = app(Pow::class);
        $escalation = app(Escalation::class);

        foreach (range(1, 5) as $ignored) {
            $pow->create('both');
        }

        $escalation->record('both');

        // one step for five requests, one step for the failure
        expect($pow->difficultyFor('both'))->toBe(20);
    });

    it('leaves a quiet client at the floor', function () {
        config()->set('captcha.pow.difficulty', 16);
        config()->set('captcha.pow.volume', ['every' => 5, 'step' => 2]);

        $pow = app(Pow::class);

        foreach (range(1, 20) as $ignored) {
            $pow->create('noisy');
        }

        expect($pow->create('quiet')->difficulty)->toBe(16);
    });

    it('clears both counters on success', function () {
        config()->set('captcha.pow.volume', ['every' => 2, 'step' => 2]);

        $pow = app(Pow::class);
        $escalation = app(Escalation::class);

        $pow->create('k');
        $pow->create('k');
        $escalation->record('k');

        expect($pow->difficultyFor('k'))->toBeGreaterThan(16);

        $escalation->clear('k');

        expect($pow->difficultyFor('k'))->toBe(16)
            ->and($escalation->demands('k'))->toBe(0);
    });
});
