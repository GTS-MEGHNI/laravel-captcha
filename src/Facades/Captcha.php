<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \GtsMeghni\LaravelCaptcha\IssuedChallenge create(string $preset = 'default', ?string $ip = null)
 * @method static string|null image(string $token)
 * @method static bool verify(?string $answer, ?string $token, ?string $ip = null)
 * @method static bool enabled()
 * @method static \GtsMeghni\LaravelCaptcha\Support\Preset preset(string $name = 'default')
 * @method static \GtsMeghni\LaravelCaptcha\Challenges\ChallengeManager challenges()
 * @method static bool requiredFor(string $key, ?int $after = null)
 * @method static int recordFailure(string $key)
 * @method static void clearFailures(string $key)
 * @method static \GtsMeghni\LaravelCaptcha\Support\Escalation escalation()
 *
 * @see \GtsMeghni\LaravelCaptcha\Captcha
 */
class Captcha extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GtsMeghni\LaravelCaptcha\Captcha::class;
    }
}
