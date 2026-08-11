<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Facades\Captcha as CaptchaFacade;
use GtsMeghni\LaravelCaptcha\Validation\Rules\Captcha as CaptchaRule;
use Illuminate\Support\Facades\Route;

it('asks for nothing until a key has failed enough', function () {
    expect(CaptchaFacade::requiredFor('login:someone@example.test'))->toBeFalse();

    CaptchaFacade::recordFailure('login:someone@example.test');

    expect(CaptchaFacade::requiredFor('login:someone@example.test'))->toBeFalse();

    CaptchaFacade::recordFailure('login:someone@example.test');

    expect(CaptchaFacade::requiredFor('login:someone@example.test'))->toBeTrue();
});

it('counts failures per key without collisions', function () {
    CaptchaFacade::recordFailure('login:a@example.test');
    CaptchaFacade::recordFailure('login:a@example.test');

    expect(CaptchaFacade::requiredFor('login:a@example.test'))->toBeTrue()
        ->and(CaptchaFacade::requiredFor('login:b@example.test'))->toBeFalse();
});

it('returns the running count as it records', function () {
    expect(CaptchaFacade::recordFailure('k'))->toBe(1)
        ->and(CaptchaFacade::recordFailure('k'))->toBe(2)
        ->and(CaptchaFacade::recordFailure('k'))->toBe(3)
        ->and(CaptchaFacade::escalation()->attempts('k'))->toBe(3);
});

it('forgets failures once cleared', function () {
    CaptchaFacade::recordFailure('k');
    CaptchaFacade::recordFailure('k');

    expect(CaptchaFacade::requiredFor('k'))->toBeTrue();

    CaptchaFacade::clearFailures('k');

    expect(CaptchaFacade::requiredFor('k'))->toBeFalse()
        ->and(CaptchaFacade::escalation()->attempts('k'))->toBe(0);
});

it('takes the threshold from config, and from the call when given', function () {
    config()->set('captcha.escalation.after', 5);

    expect(CaptchaFacade::escalation()->threshold())->toBe(5);

    CaptchaFacade::recordFailure('k');

    expect(CaptchaFacade::requiredFor('k'))->toBeFalse()
        ->and(CaptchaFacade::requiredFor('k', 1))->toBeTrue();
});

it('forgets failures after the decay window', function () {
    config()->set('captcha.escalation.decay', 60);

    CaptchaFacade::recordFailure('k');
    CaptchaFacade::recordFailure('k');

    expect(CaptchaFacade::requiredFor('k'))->toBeTrue();

    $this->travel(61)->seconds();

    expect(CaptchaFacade::requiredFor('k'))->toBeFalse();
});

it('demands the captcha only on a later attempt', function () {
    Route::post('_test/login', function () {
        $key = 'login:'.request()->input('email');

        $rules = [];

        if (CaptchaFacade::requiredFor($key)) {
            $rules['captcha_token'] = ['required', 'string'];
            $rules['captcha'] = ['required', new CaptchaRule];
        }

        request()->validate($rules);

        // Stand in for a wrong password.
        CaptchaFacade::recordFailure($key);

        return response()->json(['captcha_required' => CaptchaFacade::requiredFor($key)], 401);
    });

    // First two attempts need no captcha, and the second says one is now due.
    $this->postJson('_test/login', ['email' => 'a@b.test'])
        ->assertStatus(401)
        ->assertJsonPath('captcha_required', false);

    $this->postJson('_test/login', ['email' => 'a@b.test'])
        ->assertStatus(401)
        ->assertJsonPath('captcha_required', true);

    // The third is rejected for missing the captcha rather than the password.
    $this->postJson('_test/login', ['email' => 'a@b.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('captcha');
});
