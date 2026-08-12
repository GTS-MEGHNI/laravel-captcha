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
    expect(glob(fontsPath().'/*.ttf'))->not->toBeEmpty()
        ->and(glob(backgroundsPath().'/*.png'))->not->toBeEmpty();
});

/*
| The asset paths default to null and are resolved by the provider, whose __DIR__
| stays inside the package. Resolving them in config/captcha.php instead pointed
| at the application's resources/ directory once that file was published.
|
| See https://github.com/GTS-MEGHNI/laravel-captcha/issues/1.
*/
it('defaults the asset paths to null so a published config cannot re-point them', function () {
    expect(config('captcha.fonts_path'))->toBeNull()
        ->and(config('captcha.backgrounds_path'))->toBeNull();
});

it('renders with the bundled assets when the config is published', function () {
    $published = base_path('config');

    config([
        'captcha.fonts_path' => null,
        'captcha.backgrounds_path' => null,
        'captcha.default.background.mode' => 'images',
    ]);

    expect(fontsPath())->not->toStartWith($published)
        ->and(backgroundsPath())->not->toStartWith($published);

    $captcha = app(Captcha::class);

    expect($captcha->image($captcha->create()->token))->not->toBeNull();
});

it('still honours an explicit asset path', function () {
    $fonts = __DIR__.'/../../resources/fonts';

    config(['captcha.fonts_path' => $fonts]);

    expect(fontsPath())->toBe($fonts);
});
