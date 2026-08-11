<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('issues a challenge with a token and an image url', function () {
    $issued = app(Captcha::class)->create();

    expect($issued->token)->toHaveLength(40)
        ->and($issued->expiresIn)->toBe(120)
        ->and($issued->url)->toEndWith($issued->token.'.png')
        ->and($issued->toArray())->toHaveKeys(['token', 'url', 'expires_in']);
});

it('does not store the answer in the clear', function () {
    $issued = app(Captcha::class)->create();

    $stored = app(ChallengeStore::class)->find($issued->token);

    expect($stored)->not->toBeNull()
        ->and($stored->digest)->toHaveLength(64)
        ->and(implode('', $stored->glyphs))->not->toBe($stored->digest);
});

it('verifies the answer for a text challenge', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $answer = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);

    expect($captcha->verify($answer, $issued->token))->toBeTrue();
});

it('ignores case when the preset is not sensitive', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $answer = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);

    expect($captcha->verify(Str::upper($answer), $issued->token))->toBeTrue();
});

it('rejects a wrong answer and burns the token', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $answer = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);

    expect($captcha->verify('definitely-wrong', $issued->token))->toBeFalse()
        ->and($captcha->verify($answer, $issued->token))->toBeFalse();
});

it('allows a token to be used only once', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $answer = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);

    expect($captcha->verify($answer, $issued->token))->toBeTrue()
        ->and($captcha->verify($answer, $issued->token))->toBeFalse();
});

it('rejects an unknown or empty token', function () {
    $captcha = app(Captcha::class);

    expect($captcha->verify('abc', Str::random(40)))->toBeFalse()
        ->and($captcha->verify('abc', ''))->toBeFalse()
        ->and($captcha->verify('abc', null))->toBeFalse()
        ->and($captcha->verify(null, Str::random(40)))->toBeFalse();
});

it('renders a png at the size the preset asks for', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $png = $captcha->image($issued->token);

    expect($png)->not->toBeNull();

    $size = getimagesizefromstring($png);

    expect($size[0])->toBe(180)
        ->and($size[1])->toBe(46)
        ->and($size['mime'])->toBe('image/png');
});

it('renders the same bytes when the image is refetched', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    expect($captcha->image($issued->token))->toBe($captcha->image($issued->token));
});

it('renders different images for different challenges', function () {
    $captcha = app(Captcha::class);

    expect($captcha->image($captcha->create()->token))
        ->not->toBe($captcha->image($captcha->create()->token));
});

it('draws every background mode', function (string $mode, string $style) {
    config()->set('captcha.default.background', ['mode' => $mode, 'style' => $style, 'color' => '#ecf2f4']);

    $captcha = app(Captcha::class);

    $size = getimagesizefromstring($captcha->image($captcha->create()->token));

    expect($size[0])->toBe(180);
})->with([
    ['images', 'noise'],
    ['generated', 'noise'],
    ['generated', 'mesh'],
    ['generated', 'blobs'],
    ['solid', 'noise'],
]);

it('returns no image for an unknown token', function () {
    expect(app(Captcha::class)->image(Str::random(40)))->toBeNull();
});

it('stops rendering once the challenge is answered', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $answer = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);
    $captcha->verify($answer, $issued->token);

    expect($captcha->image($issued->token))->toBeNull();
});

it('throws for a preset that is not configured', function () {
    app(Captcha::class)->create('nope');
})->throws(CaptchaException::class, 'preset [nope]');

it('passes everything when disabled', function () {
    config()->set('captcha.enabled', false);

    Route::post('_test/disabled', fn () => response('ok'))->middleware('captcha');

    $this->postJson('_test/disabled')->assertOk();
});

it('warps deterministically and only when an amplitude is set', function () {
    $captcha = app(Captcha::class);
    $issued = $captcha->create();

    $warped = $captcha->image($issued->token);

    expect($warped)->toBe($captcha->image($issued->token));

    config()->set('captcha.default.wave.amplitude', 0);

    expect(app(Captcha::class)->image($issued->token))->not->toBe($warped);
});

it('leaves the canvas size alone whatever the distortion settings', function (array $settings) {
    foreach ($settings as $key => $value) {
        config()->set("captcha.default.$key", $value);
    }

    $captcha = app(Captcha::class);

    $size = getimagesizefromstring($captcha->image($captcha->create()->token));

    expect($size[0])->toBe(180)
        ->and($size[1])->toBe(46);
})->with([
    'no distortion' => [['overlap' => 0.0, 'jitter' => 0.0, 'angle' => 0, 'wave' => ['amplitude' => 0]]],
    'clamped overlap' => [['overlap' => 5.0]],
    'heavy warp' => [['wave' => ['amplitude' => 12, 'period' => 3]]],
    'no speckle' => [['speckle' => ['tone' => 'none', 'density' => 0.5]]],
    'light speckle' => [['speckle' => ['tone' => 'light', 'density' => 0.2]]],
]);

it('reports a background directory that holds no images', function () {
    config()->set('captcha.default.background', ['mode' => 'images', 'path' => __DIR__]);

    $captcha = app(Captcha::class);

    $captcha->image($captcha->create()->token);
})->throws(CaptchaException::class, 'No background images');
