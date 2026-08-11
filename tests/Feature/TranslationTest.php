<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use Illuminate\Support\Facades\Route;

it('ships a message file per supported locale', function (string $locale) {
    app()->setLocale($locale);

    expect(trans('captcha::messages.missing'))->not->toBe('captcha::messages.missing')
        ->and(trans('captcha::messages.invalid'))->not->toBe('captcha::messages.invalid');
})->with(['en', 'fr', 'ar']);

it('keeps the same keys in every locale', function () {
    $en = array_keys(require __DIR__.'/../../lang/en/messages.php');

    foreach (['fr', 'ar'] as $locale) {
        expect(array_keys(require __DIR__."/../../lang/{$locale}/messages.php"))->toBe($en);
    }
});

it('fails validation in the active locale', function (string $locale, string $fragment) {
    app()->setLocale($locale);

    Route::post('_test/lang', function () {
        request()->validate([
            'captcha' => ['required', new GtsMeghni\LaravelCaptcha\Validation\Rules\Captcha],
        ]);

        return response('ok');
    });

    $token = app(Captcha::class)->create()->token;

    $this->postJson('_test/lang', ['captcha_token' => $token, 'captcha' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonFragment(['captcha' => [$fragment]]);
})->with([
    ['en', 'The captcha answer is incorrect. Please try a new image.'],
    ['fr', 'La réponse au captcha est incorrecte. Veuillez essayer une nouvelle image.'],
    ['ar', 'إجابة رمز التحقق غير صحيحة. الرجاء تجربة صورة جديدة.'],
]);

it('reports a missing answer in the active locale through the middleware', function () {
    app()->setLocale('fr');

    Route::post('_test/lang-mw', fn () => response('ok'))->middleware('captcha');

    $this->postJson('_test/lang-mw')
        ->assertStatus(422)
        ->assertJsonFragment(['captcha' => ['La réponse au captcha est obligatoire.']]);
});

it('renders the same Latin challenge whatever the locale', function () {
    foreach (['en', 'fr', 'ar'] as $locale) {
        app()->setLocale($locale);

        $issued = app(Captcha::class)->create();
        $drawn = implode('', app(ChallengeStore::class)->find($issued->token)->glyphs);

        // Latin letters and Western digits only, in every locale.
        expect($drawn)->toMatch('/^[0-9a-zA-Z]+$/');
    }
});
