<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Pow\Pow;
use GtsMeghni\LaravelCaptcha\Support\Escalation;
use GtsMeghni\LaravelCaptcha\Validation\Rules\ProofOfWork;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Do what the browser does: count up until the digest opens with enough zeros.
 */
function solvePow(string $salt, int $difficulty): string
{
    $pow = app(Pow::class);

    for ($nonce = 0; $nonce < 5_000_000; $nonce++) {
        if ($pow->leadingZeroBits(hash('sha256', $salt.$nonce)) >= $difficulty) {
            return (string) $nonce;
        }
    }

    throw new RuntimeException('No nonce found; difficulty is too high for a test.');
}

/**
 * The opposite: a nonce the digest definitely does not satisfy.
 *
 * At the 8 bits these tests run at, any nonce picked in advance solves one
 * challenge in 256, so hardcoding one fails the suite about that often.
 */
function failPow(string $salt, int $difficulty): string
{
    $pow = app(Pow::class);

    for ($nonce = 0; $nonce < 5_000_000; $nonce++) {
        if ($pow->leadingZeroBits(hash('sha256', $salt.$nonce)) < $difficulty) {
            return (string) $nonce;
        }
    }

    throw new RuntimeException('No failing nonce found, which no real difficulty allows.');
}

beforeEach(function () {
    // Keep the tests quick: correctness does not depend on the bit count.
    config()->set('captcha.pow.difficulty', 8);
});

it('issues a challenge with a server-chosen salt', function () {
    $challenge = app(Pow::class)->create();

    expect($challenge->token)->toHaveLength(40)
        ->and($challenge->salt)->toHaveLength(32)
        ->and($challenge->difficulty)->toBe(8)
        ->and($challenge->toArray())->toHaveKeys(['token', 'salt', 'difficulty', 'algorithm', 'expires_in', 'expires_at'])
        ->and($challenge->toArray()['algorithm'])->toBe('sha256');
});

it('never issues the same salt twice', function () {
    $pow = app(Pow::class);

    $salts = array_map(fn () => $pow->create()->salt, range(1, 20));

    expect(array_unique($salts))->toHaveCount(20);
});

it('accepts a nonce that satisfies the difficulty', function () {
    $pow = app(Pow::class);
    $challenge = $pow->create();

    expect($pow->verify(solvePow($challenge->salt, $challenge->difficulty), $challenge->token))->toBeTrue();
});

it('rejects a nonce that does not', function () {
    $pow = app(Pow::class);
    $challenge = $pow->create();

    // 0 satisfies 8 bits only by luck; find one that definitely does not.
    $bad = failPow($challenge->salt, $challenge->difficulty);

    expect($pow->verify($bad, $challenge->token))->toBeFalse();
});

it('spends the token whether the nonce was right or wrong', function () {
    $pow = app(Pow::class);

    $challenge = $pow->create();
    $nonce = solvePow($challenge->salt, $challenge->difficulty);

    expect($pow->verify($nonce, $challenge->token))->toBeTrue()
        ->and($pow->verify($nonce, $challenge->token))->toBeFalse();

    $second = $pow->create();

    expect($pow->verify(failPow($second->salt, $second->difficulty), $second->token))->toBeFalse()
        ->and($pow->verify(solvePow($second->salt, $second->difficulty), $second->token))->toBeFalse();
});

it('rejects an unknown token, and junk nonces, without hashing', function () {
    $pow = app(Pow::class);
    $challenge = $pow->create();

    expect($pow->verify('1', Str::random(40)))->toBeFalse()
        ->and($pow->verify('', $challenge->token))->toBeFalse()
        ->and($pow->verify(null, $challenge->token))->toBeFalse()
        ->and($pow->verify('1', null))->toBeFalse()
        ->and($pow->verify('not-a-number', $challenge->token))->toBeFalse()
        ->and($pow->verify(str_repeat('9', 25), $challenge->token))->toBeFalse();
});

it('counts leading zero bits correctly', function (string $digest, int $bits) {
    expect(app(Pow::class)->leadingZeroBits($digest))->toBe($bits);
})->with([
    ['ffff', 0],
    ['7fff', 1],
    ['3fff', 2],
    ['1fff', 3],
    ['0fff', 4],
    ['00ff', 8],
    ['0000df25082abf07', 16],
    ['0000000000000000', 64],
]);

it('clamps the configured difficulty into a workable range', function () {
    config()->set('captcha.pow.difficulty', 0);
    expect(app(Pow::class)->difficulty())->toBe(1);

    config()->set('captcha.pow.difficulty', 999);
    expect(app(Pow::class)->difficulty())->toBe(28);
});

it('issues over http', function () {
    $body = $this->getJson('api/captcha/pow')->assertOk()->json();

    expect($body)->toHaveKeys(['token', 'salt', 'difficulty', 'algorithm', 'expires_in', 'expires_at'])
        ->and($body['difficulty'])->toBe(8);
});

it('guards a route through the validation rule', function () {
    Route::post('_test/pow', function () {
        request()->validate(['pow_nonce' => ['required', new ProofOfWork]]);

        return response('ok');
    });

    $challenge = app(Pow::class)->create();

    $this->postJson('_test/pow', [
        'pow_token' => $challenge->token,
        'pow_nonce' => solvePow($challenge->salt, $challenge->difficulty),
    ])->assertOk();
});

it('fails the rule when the work was not done', function () {
    Route::post('_test/pow', function () {
        request()->validate(['pow_nonce' => ['required', new ProofOfWork]]);

        return response('ok');
    });

    $challenge = app(Pow::class)->create();

    $nonce = failPow($challenge->salt, $challenge->difficulty);

    $this->postJson('_test/pow', ['pow_token' => $challenge->token, 'pow_nonce' => $nonce])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pow_nonce');
});

it('passes everything through when disabled', function () {
    config()->set('captcha.pow.enabled', false);

    Route::post('_test/pow', function () {
        request()->validate(['pow_nonce' => ['required', new ProofOfWork]]);

        return response('ok');
    });

    $this->postJson('_test/pow', ['pow_nonce' => 'anything'])->assertOk();
});

it('prices a first-time caller at the floor', function () {
    config()->set('captcha.pow.difficulty', 16);

    expect(app(Pow::class)->difficultyFor('pow:1.2.3.4'))->toBe(16)
        ->and(app(Pow::class)->difficultyFor(null))->toBe(16);
});

it('raises difficulty a step per recorded failure', function () {
    config()->set('captcha.pow.difficulty', 16);
    config()->set('captcha.pow.step', 2);
    config()->set('captcha.pow.max_difficulty', 24);

    $pow = app(Pow::class);
    $escalation = app(Escalation::class);

    expect($pow->difficultyFor('k'))->toBe(16);

    $escalation->record('k');
    expect($pow->difficultyFor('k'))->toBe(18);

    $escalation->record('k');
    expect($pow->difficultyFor('k'))->toBe(20);

    foreach (range(1, 20) as $ignored) {
        $escalation->record('k');
    }

    expect($pow->difficultyFor('k'))->toBe(24);
});

it('prices each key on its own history', function () {
    config()->set('captcha.pow.difficulty', 16);

    app(Escalation::class)->record('noisy');

    expect(app(Pow::class)->difficultyFor('noisy'))->toBe(18)
        ->and(app(Pow::class)->difficultyFor('quiet'))->toBe(16);
});

it('stops escalating when the feature is off', function () {
    config()->set('captcha.pow.difficulty', 16);
    config()->set('captcha.pow.escalate', false);

    app(Escalation::class)->record('k');
    app(Escalation::class)->record('k');

    expect(app(Pow::class)->difficultyFor('k'))->toBe(16);
});

it('issues the escalated difficulty over http, keyed on the caller', function () {
    config()->set('captcha.pow.difficulty', 8);
    config()->set('captcha.pow.step', 3);

    expect($this->getJson('api/captcha/pow')->json('difficulty'))->toBe(8);

    // The controller keys on the request IP, which Testbench reports as 127.0.0.1.
    app(Escalation::class)->record('pow:127.0.0.1');

    expect($this->getJson('api/captcha/pow')->json('difficulty'))->toBe(11);
});

it('honours the difficulty it issued, not the current setting', function () {
    config()->set('captcha.pow.difficulty', 8);

    $pow = app(Pow::class);
    $challenge = $pow->create();

    // Raising the floor after issuing must not invalidate work already done.
    config()->set('captcha.pow.difficulty', 24);

    expect($pow->verify(solvePow($challenge->salt, 8), $challenge->token))->toBeTrue();
});
