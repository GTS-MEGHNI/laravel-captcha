<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Store\ChallengeStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

it('lets only the first caller consume a token', function () {
    $captcha = app(Captcha::class);
    $store = app(ChallengeStore::class);

    $issued = $captcha->create();

    expect($store->pull($issued->token))->not->toBeNull()
        ->and($store->pull($issued->token))->toBeNull();
});

it('refuses to consume a token while another request holds the lock', function () {
    $captcha = app(Captcha::class);
    $store = app(ChallengeStore::class);

    $issued = $captcha->create();

    /** @var LockProvider $cache */
    $cache = Cache::store()->getStore();

    // Stand in for a concurrent request that has taken the lock but has not yet
    // deleted the entry — exactly the window Cache::pull() leaves open.
    $lock = $cache->lock('captcha:'.$issued->token.':lock', 5);

    expect($lock->get())->toBeTrue()
        ->and($store->pull($issued->token))->toBeNull()
        ->and($store->find($issued->token))->not->toBeNull();

    $lock->release();

    expect($store->pull($issued->token))->not->toBeNull();
});

it('rejects a duplicate submission of one solved captcha', function () {
    $captcha = app(Captcha::class);
    $answer = implode('', app(ChallengeStore::class)->find(
        $token = $captcha->create()->token,
    )->glyphs);

    $accepted = 0;

    foreach (range(1, 5) as $ignored) {
        if ($captcha->verify($answer, $token)) {
            $accepted++;
        }
    }

    expect($accepted)->toBe(1);
});
