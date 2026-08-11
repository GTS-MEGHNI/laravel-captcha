<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use GtsMeghni\LaravelCaptcha\Validation\Rules\Captcha as CaptchaRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function answerFor(string $token): string
{
    return implode('', app(ChallengeStore::class)->find($token)->glyphs);
}

it('issues a challenge over http', function () {
    $response = $this->getJson('api/captcha');

    $response->assertOk()
        ->assertJsonStructure(['token', 'url', 'expires_in', 'expires_at']);

    expect($response->json('expires_in'))->toBe(120)
        ->and($response->json('url'))->toContain($response->json('token').'.png');
});

it('issues a challenge for a named preset', function () {
    config()->set('captcha.wide', ['width' => 300]);

    $token = $this->getJson('api/captcha?preset=wide')->assertOk()->json('token');

    expect(getimagesizefromstring(app(Captcha::class)->image($token))[0])->toBe(300);
});

it('streams the image without caching it', function () {
    $url = $this->getJson('api/captcha')->json('url');

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('cache-control', 'must-revalidate, no-cache, no-store, private');

    expect(substr($response->getContent(), 1, 3))->toBe('PNG');
});

it('404s an image for an unknown token', function () {
    $this->get('api/captcha/'.Str::random(40).'.png')->assertNotFound();
});

it('404s a token that does not look like a token', function () {
    $this->get('api/captcha/short.png')->assertNotFound();
});

it('passes validation with the right answer', function () {
    Route::post('_test/rule', function () {
        request()->validate([
            'captcha' => ['required', new CaptchaRule],
        ]);

        return response('ok');
    });

    $token = $this->getJson('api/captcha')->json('token');

    $this->postJson('_test/rule', [
        'captcha_token' => $token,
        'captcha' => answerFor($token),
    ])->assertOk();
});

it('fails validation with the wrong answer', function () {
    Route::post('_test/rule', function () {
        request()->validate([
            'captcha' => ['required', new CaptchaRule],
        ]);

        return response('ok');
    });

    $token = $this->getJson('api/captcha')->json('token');

    $this->postJson('_test/rule', [
        'captcha_token' => $token,
        'captcha' => 'wrong',
    ])->assertStatus(422)->assertJsonValidationErrors('captcha');
});

it('fails validation when the token is absent', function () {
    Route::post('_test/rule', function () {
        request()->validate([
            'captcha' => ['required', new CaptchaRule],
        ]);

        return response('ok');
    });

    $this->postJson('_test/rule', ['captcha' => 'anything'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('captcha');
});

it('guards a route with the middleware', function () {
    Route::post('_test/middleware', fn () => response('ok'))->middleware('captcha');

    $token = $this->getJson('api/captcha')->json('token');

    $this->postJson('_test/middleware', [
        'captcha_token' => $token,
        'captcha' => answerFor($token),
    ])->assertOk();
});

it('rejects a request the middleware cannot verify', function () {
    Route::post('_test/middleware', fn () => response('ok'))->middleware('captcha');

    $this->postJson('_test/middleware', ['captcha_token' => Str::random(40), 'captcha' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('captcha');
});

it('reads the field names from config', function () {
    config()->set('captcha.fields', ['token' => 'recaptchaToken', 'answer' => 'userInput']);

    Route::post('_test/legacy', fn () => response('ok'))->middleware('captcha');

    $issued = app(Captcha::class)->create();

    $this->postJson('_test/legacy', [
        'recaptchaToken' => $issued->token,
        'userInput' => answerFor($issued->token),
    ])->assertOk();
});

it('names both endpoints', function () {
    expect(Route::has('captcha.issue'))->toBeTrue()
        ->and(Route::has('captcha.image'))->toBeTrue();
});

it('rejects an unknown preset with a 422 rather than a 500', function () {
    $this->getJson('api/captcha?preset=nope')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unknown captcha preset [nope].');
});

it('falls back to the default preset for an empty preset parameter', function () {
    $this->getJson('api/captcha?preset=')->assertOk()->assertJsonStructure(['token', 'url', 'expires_in', 'expires_at']);
});

it('throttles the issue endpoint', function () {
    config()->set('captcha.routes.throttle.issue', '60,1');

    expect(collect(app('router')->getRoutes()->getByName('captcha.issue')->gatherMiddleware())
        ->contains('throttle:60,1'))->toBeTrue();
});

it('serves the endpoints under the api prefix', function () {
    expect(route('captcha.issue', [], false))->toBe('/api/captcha');

    $this->getJson('captcha')->assertNotFound();
});

it('states the expiry in seconds and as an absolute stamp', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $body = $this->getJson('api/captcha')->assertOk()->json();

    expect($body['expires_in'])->toBe(120)
        ->and($body['expires_at'])->toStartWith('2026-08-10T12:02:00');

    Carbon::setTestNow();
});
