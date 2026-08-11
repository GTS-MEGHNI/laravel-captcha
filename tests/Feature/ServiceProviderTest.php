<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Challenges\ChallengeManager;
use GtsMeghni\LaravelCaptcha\Facades\Captcha as CaptchaFacade;
use GtsMeghni\LaravelCaptcha\Rendering\Renderer;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use Illuminate\Contracts\Console\Kernel;

it('binds the package services as singletons', function (string $abstract) {
    expect(app($abstract))->toBe(app($abstract));
})->with([
    Captcha::class,
    ChallengeManager::class,
    ChallengeStore::class,
    Renderer::class,
]);

it('merges the package config', function () {
    expect(config('captcha.fields.token'))->toBe('captcha_token')
        ->and(config('captcha.default.type'))->toBe('text')
        ->and(config('captcha.pow.difficulty'))->toBe(16)
        ->and(config('captcha.pow.enabled'))->toBeTrue();
});

it('resolves the facade to the captcha service', function () {
    expect(CaptchaFacade::getFacadeRoot())->toBe(app(Captcha::class));
});

it('loads the package translations', function () {
    expect(trans('captcha::messages.invalid'))->not->toBe('captcha::messages.invalid');
});

it('registers the middleware alias', function () {
    expect(app('router')->getMiddleware())->toHaveKey('captcha');
});

it('registers the preview command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('captcha:preview');
});

it('ships the fonts and backgrounds it points at', function () {
    expect(glob(config('captcha.fonts_path').'/*.ttf'))->not->toBeEmpty()
        ->and(glob(config('captcha.backgrounds_path').'/*.png'))->not->toBeEmpty();
});
