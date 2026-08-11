<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha;

use GtsMeghni\LaravelCaptcha\Challenges\ChallengeManager;
use GtsMeghni\LaravelCaptcha\Console\Commands\CaptchaPreviewCommand;
use GtsMeghni\LaravelCaptcha\Console\Commands\CaptchaPruneCommand;
use GtsMeghni\LaravelCaptcha\Http\Middleware\ValidateCaptcha;
use GtsMeghni\LaravelCaptcha\Pow\Pow;
use GtsMeghni\LaravelCaptcha\Rendering\BackgroundPainter;
use GtsMeghni\LaravelCaptcha\Rendering\InterventionRenderer;
use GtsMeghni\LaravelCaptcha\Rendering\Renderer;
use GtsMeghni\LaravelCaptcha\Store\ChallengePruner;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use GtsMeghni\LaravelCaptcha\Store\TokenStore;
use GtsMeghni\LaravelCaptcha\Support\Escalation;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class CaptchaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/captcha.php', 'captcha');

        $this->registerImageManager();

        $this->app->singleton(ChallengeManager::class);

        $this->app->singleton(BackgroundPainter::class, fn (Application $app): BackgroundPainter => new BackgroundPainter(
            $app->make(Filesystem::class),
            $app->make(ImageManager::class),
            Value::string($app->make(Config::class)->get('captcha.backgrounds_path'), __DIR__.'/../resources/backgrounds'),
        ));

        $this->app->singleton(Renderer::class, fn (Application $app): InterventionRenderer => new InterventionRenderer(
            $app->make(ImageManager::class),
            $app->make(BackgroundPainter::class),
            $app->make(Filesystem::class),
            Value::string($app->make(Config::class)->get('captcha.fonts_path'), __DIR__.'/../resources/fonts'),
        ));

        $this->app->singleton(ChallengeStore::class, function (Application $app): ChallengeStore {
            $config = $app->make(Config::class);

            $store = $config->get('captcha.cache.store');

            return new ChallengeStore(
                $app->make(CacheFactory::class),
                is_string($store) && $store !== '' ? $store : null,
                Value::string($config->get('captcha.cache.prefix'), 'captcha'),
                Value::int($config->get('captcha.cache.expire'), 120),
            );
        });

        $this->app->singleton(ChallengePruner::class, function (Application $app): ChallengePruner {
            $config = $app->make(Config::class);

            $store = $config->get('captcha.cache.store');

            return new ChallengePruner(
                $app->make(CacheFactory::class),
                $config,
                $app->make(Filesystem::class),
                is_string($store) && $store !== '' ? $store : null,
                Value::string($config->get('captcha.cache.prefix'), 'captcha'),
            );
        });

        $this->app->singleton(Pow::class, function (Application $app): Pow {
            $config = $app->make(Config::class);

            $store = $config->get('captcha.cache.store');

            return new Pow(
                $config,
                new TokenStore(
                    $app->make(CacheFactory::class),
                    is_string($store) && $store !== '' ? $store : null,
                    Value::string($config->get('captcha.cache.prefix'), 'captcha').':pow',
                    Value::int($config->get('captcha.pow.expire'), 120),
                ),
                $app->make(Escalation::class),
            );
        });

        $this->app->singleton(Captcha::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRoutes();

        $this->app->make(Router::class)->aliasMiddleware('captcha', ValidateCaptcha::class);

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'captcha');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/captcha.php' => config_path('captcha.php'),
        ], ['laravel-captcha', 'laravel-captcha-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/captcha'),
        ], ['laravel-captcha', 'laravel-captcha-lang']);

        $this->publishes([
            __DIR__.'/../resources/fonts' => public_path('vendor/laravel-captcha/fonts'),
        ], ['laravel-captcha', 'laravel-captcha-fonts']);

        $this->publishes([
            __DIR__.'/../resources/backgrounds' => public_path('vendor/laravel-captcha/backgrounds'),
        ], ['laravel-captcha', 'laravel-captcha-backgrounds']);

        $this->commands([
            CaptchaPreviewCommand::class,
            CaptchaPruneCommand::class,
        ]);
    }

    /**
     * Bind an Intervention manager unless the application already provides one.
     */
    protected function registerImageManager(): void
    {
        if ($this->app->bound(ImageManager::class)) {
            return;
        }

        $this->app->singleton(ImageManager::class, function (Application $app): ImageManager {
            $driver = $app->make(Config::class)->get('captcha.driver', 'gd') === 'imagick'
                ? new ImagickDriver
                : new GdDriver;

            return new ImageManager($driver);
        });
    }

    /**
     * Register the issue and stream endpoints.
     */
    protected function registerRoutes(): void
    {
        $config = $this->app->make(Config::class);

        if (! (bool) $config->get('captcha.routes.enabled', true)) {
            return;
        }

        /** @var list<string> $middleware */
        $middleware = (array) $config->get('captcha.routes.middleware', ['api']);

        Route::group([
            'prefix' => Value::string($config->get('captcha.routes.prefix'), 'api/captcha'),
            'middleware' => $middleware,
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/captcha.php');
        });
    }
}
