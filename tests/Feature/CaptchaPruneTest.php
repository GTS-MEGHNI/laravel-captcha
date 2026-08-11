<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Store\ChallengePruner;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backdate a live entry so it counts as expired without waiting for its TTL.
 */
function expireFileEntry(string $store, string $key): string
{
    /** @var FileStore $fileStore */
    $fileStore = Cache::store($store)->getStore();

    $path = $fileStore->path($fileStore->getPrefix().$key);
    $contents = (string) file_get_contents($path);

    file_put_contents($path, '1000000000'.substr($contents, 10));

    return $path;
}

it('reports nothing to do on a store that expires its own entries', function () {
    config()->set('captcha.cache.store', 'array');

    $result = app(ChallengePruner::class)->prune();

    expect($result->supported)->toBeFalse()
        ->and($result->removed)->toBe(0)
        ->and($result->driver)->toBe('array');

    $this->artisan('captcha:prune')
        ->expectsOutputToContain('expires its own entries')
        ->assertSuccessful();
});

describe('file store', function () {
    beforeEach(function () {
        config()->set('cache.stores.captcha_file', [
            'driver' => 'file',
            'path' => storage_path('framework/cache/captcha-prune-test'),
        ]);
        config()->set('captcha.cache.store', 'captcha_file');

        Cache::store('captcha_file')->clear();
    });

    it('deletes an abandoned challenge once it has expired', function () {
        $issued = app(Captcha::class)->create();

        $path = expireFileEntry('captcha_file', 'captcha:'.$issued->token);

        expect($path)->toBeFile();

        $result = app(ChallengePruner::class)->prune();

        expect($result->driver)->toBe('file')
            ->and($result->supported)->toBeTrue()
            ->and($result->scoped)->toBeFalse()
            ->and($result->removed)->toBe(1)
            ->and(file_exists($path))->toBeFalse();
    });

    it('leaves a live challenge alone', function () {
        $issued = app(Captcha::class)->create();

        expect(app(ChallengePruner::class)->prune()->removed)->toBe(0)
            ->and(app(Captcha::class)->image($issued->token))->not->toBeNull();
    });

    it('warns that a hashed store cannot be swept selectively', function () {
        app(Captcha::class)->create();

        $this->artisan('captcha:prune')
            ->expectsOutputToContain('every expired entry in that directory')
            ->assertSuccessful();
    });
});

describe('database store', function () {
    beforeEach(function () {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function ($table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        config()->set('cache.stores.captcha_database', [
            'driver' => 'database',
            'table' => 'cache',
        ]);
        config()->set('captcha.cache.store', 'captcha_database');
    });

    it('deletes expired captcha rows and keeps live ones', function () {
        $expired = app(Captcha::class)->create();
        $live = app(Captcha::class)->create();

        $store = Cache::store('captcha_database')->getStore();

        DB::table('cache')
            ->where('key', $store->getPrefix().'captcha:'.$expired->token)
            ->update(['expiration' => 1000000000]);

        $result = app(ChallengePruner::class)->prune();

        expect($result->driver)->toBe('database')
            ->and($result->scoped)->toBeTrue()
            ->and($result->removed)->toBe(1)
            ->and(app(Captcha::class)->image($live->token))->not->toBeNull();
    });

    it('never touches an expired row belonging to something else', function () {
        $store = Cache::store('captcha_database')->getStore();

        DB::table('cache')->insert([
            'key' => $store->getPrefix().'invoices:'.Str::random(8),
            'value' => serialize('someone else'),
            'expiration' => 1000000000,
        ]);

        expect(app(ChallengePruner::class)->prune()->removed)->toBe(0)
            ->and(DB::table('cache')->count())->toBe(1);
    });

    it('reports the row count it removed', function () {
        $this->artisan('captcha:prune')
            ->expectsOutputToContain('from the [database] store')
            ->assertSuccessful();
    });

    /**
     * A prefix carrying LIKE wildcards is ordinary — Laravel's own default was
     * `laravel_cache_` for years, and an app named `Acme_Billing` still produces
     * one. Both halves of the handling have to hold: the wildcards are escaped,
     * and the escape character is declared, or the sweep matches nothing at all.
     */
    it('sweeps its own rows and spares others under a prefix full of wildcards', function () {
        config()->set('cache.stores.captcha_database.prefix', 'app_cache%');

        $expired = app(Captcha::class)->create();
        $live = app(Captcha::class)->create();

        DB::table('cache')
            ->where('key', 'app_cache%captcha:'.$expired->token)
            ->update(['expiration' => 1000000000]);

        // Matches only if `_` and `%` are left to act as wildcards.
        DB::table('cache')->insert([
            'key' => 'appXcacheZZZcaptcha:'.Str::random(8),
            'value' => serialize('someone else'),
            'expiration' => 1000000000,
        ]);

        expect(app(ChallengePruner::class)->prune()->removed)->toBe(1)
            ->and(DB::table('cache')->where('key', 'app_cache%captcha:'.$live->token)->exists())->toBeTrue()
            ->and(DB::table('cache')->where('key', 'like', 'appXcache%')->exists())->toBeTrue();
    });
});
