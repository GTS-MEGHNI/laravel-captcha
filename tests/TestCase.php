<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Tests;

use GtsMeghni\LaravelCaptcha\CaptchaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CaptchaServiceProvider::class,
        ];
    }

    /**
     * Keep the cache in memory for the duration of a test.
     *
     * testbench.yaml points the workbench at the file store so `serve` works,
     * but tests inheriting that would carry rate limiter counters and expired
     * challenges from one run into the next.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // A test answers a challenge in microseconds. The plausibility floor is
        // proved on its own in PlausibilityTest rather than shaping every test.
        $app['config']->set('captcha.min_seconds', 0);
    }
}
