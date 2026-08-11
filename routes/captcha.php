<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Http\Controllers\CaptchaController;
use GtsMeghni\LaravelCaptcha\Http\Controllers\PowController;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Captcha routes
|--------------------------------------------------------------------------
|
| Registered by the service provider under the configured prefix and
| middleware. Both endpoints are public, so both carry a throttle.
|
*/

$name = Value::string(config('captcha.routes.name'), 'captcha');

/** @var array{issue?: string, image?: string, pow?: string} $throttle */
$throttle = Value::map(config('captcha.routes.throttle'));

Route::get('/', [CaptchaController::class, 'issue'])
    ->middleware(['throttle:'.($throttle['issue'] ?? '30,1')])
    ->name($name.'.issue');

// Declared before the image route so the literal path is matched first, even
// though a 40-character token pattern could never collide with it.
Route::get('/pow', [PowController::class, 'issue'])
    ->middleware(['throttle:'.($throttle['pow'] ?? '60,1')])
    ->name($name.'.pow');

Route::get('/{token}.png', [CaptchaController::class, 'image'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware(['throttle:'.($throttle['image'] ?? '60,1')])
    ->name($name.'.image');
