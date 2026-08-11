<div align="center">
    <h1>Laravel Captcha</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/gts-meghni/laravel-captcha"><img src="https://img.shields.io/packagist/v/gts-meghni/laravel-captcha.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-captcha"><img src="https://img.shields.io/packagist/php-v/gts-meghni/laravel-captcha.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-captcha"><img src="https://badge.laravel.cloud/badge/gts-meghni/laravel-captcha?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/GTS-MEGHNI/laravel-captcha/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/GTS-MEGHNI/laravel-captcha/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-captcha"><img src="https://img.shields.io/packagist/dt/gts-meghni/laravel-captcha.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Classic image-based CAPTCHA generation and validation for Laravel applications.

Invisible proof of work, plus a distorted-text challenge rendered server-side and
streamed from a URL rather than inlined as base64, so it survives a strict content
security policy. No database table of its own.

## Requirements

PHP 8.3+, Laravel 12 or 13, and either the `gd` or `imagick` extension.

`driver` names which of the two to use, and is only consulted when the
application has not already bound its own Intervention `ImageManager`; if it has,
that binding wins and this setting is ignored. `wave` is GD only.

```php
'driver' => 'gd',   // gd | imagick
```

## Installation

```bash
composer require gts-meghni/laravel-captcha
```

```bash
php artisan vendor:publish --tag="laravel-captcha"
```

Or individually. Config, translations, and the fonts and background textures,
which are read from the package by default, so publish them only to add your own:

```bash
php artisan vendor:publish --tag="laravel-captcha-config"
php artisan vendor:publish --tag="laravel-captcha-lang"
php artisan vendor:publish --tag="laravel-captcha-fonts"
php artisan vendor:publish --tag="laravel-captcha-backgrounds"
```

The browser half lives in its own package,
[`GTS-MEGHNI/laravel-captcha-js`](https://github.com/GTS-MEGHNI/laravel-captcha-js):

```bash
npm install @gts-meghni/laravel-captcha
```

It solves the proof of work, fetches image challenges and ships React and Next.js
hooks, against the same endpoints this package serves. Nothing requires it, since
the endpoints are plain JSON, but it saves reimplementing the hash loop.

## What this actually protects against

Read this before choosing settings, because the honest answer shapes them.

**No captcha stops a determined attacker.** A multimodal model reads distorted
text about as well as a person; a solving service costs a dollar per thousand.
What is achievable is making automation *expensive per attempt* and *capped per
identity*. That is what this package does, with two mechanisms that fail to
different attackers:

| | Image challenge | Proof of work |
| --- | --- | --- |
| Asks the **user** for effort | Yes, read and type | No, invisible |
| Asks the **machine** for effort | No | Yes, every attempt |
| Beaten by a vision model | **Yes** | Irrelevant, nothing to look at |
| Beaten by native or GPU hashing | Irrelevant | **Yes**, at a low difficulty |

### What those two rows mean

**"Beaten by a vision model."** The image challenge asks a question whose answer
is visible in the picture. Anything that can see can therefore answer it, and
software can now see. Screenshot the image, hand it to a multimodal model, type
back what it returns.

This is not a projection. A model read the `default` preset here at 40% first
time: 4 of 10 challenges exactly right, 87% of individual characters, with no
preprocessing and no training. Tesseract, the classic OCR the distortion was
designed against, managed 0 of 100 on the same renderer. The defence works
perfectly against the attacker of ten years ago and not at all against the one
that exists now.

And 40% understates it, because **a bot can retry for nothing**. Unsure about a
character? Discard the challenge, ask for another, try again. Three attempts at
40% succeed 78% of the time. That is why the proof of work now charges for
*requests* as well as failures (`pow.volume`): retrying has to cost something, or
partial accuracy is as good as perfect accuracy.

**"Irrelevant, nothing to look at."** The proof of work has no image, no
question and no answer to recognise. The client is asked to *spend CPU*: find a
number whose hash starts with a run of zeros. A model that reads images has
nothing to read, and being cleverer does not help. There is no shortcut through
a hash, only guessing. Vision is simply the wrong tool, the way a lockpick is the
wrong tool against a wall.

Which is why the row below it flips. The proof of work *is* beaten, by an attacker
who reimplements the hash outside a browser: one CPU core in plain JavaScript
manages 443 solves a minute at difficulty 16, and native or GPU code far more.
That is what escalating difficulty is for, and it is why running both mechanisms
is worth more than either, since the attacker who defeats one is not the attacker
who defeats the other.

Run proof of work everywhere; it costs users nothing. Add the image only where a
successful attempt is expensive to you, or only once a caller starts failing.
Neither replaces per-account lockout, and neither replaces asking for something
scarce, such as a verified phone number or a national ID, where fake accounts
are the concern.

## Proof of work

The server issues a random salt. The client must find a number, a nonce, such
that `sha256(salt + nonce)` begins with a run of zero bits. There is no shortcut,
only guessing, so it costs CPU. The server confirms with a single hash.

```http
GET /api/captcha/pow
```

```json
{
    "token": "3S3LfAg3HInA0PTtahAcu1LdgTTVO9zn4kleNcMd",
    "salt": "d203608d4c1e3146ee3b46c1b2723bb8",
    "difficulty": 16,
    "algorithm": "sha256",
    "expires_in": 120,
    "expires_at": "2026-08-10T15:05:28+00:00"
}
```

Difficulty is in bits: 16 bits means the digest must open with 16 zero bits, or
four hexadecimal zeros, which takes about 65,000 guesses on average. Each extra
bit doubles the work.

The salt is server-chosen and single-use. That is what stops one solution being
computed once and replayed forever, and it is why the client cannot supply its
own: it would supply one it had already solved.

In the browser, through the companion package:

```ts
import { obtainPow } from '@gts-meghni/laravel-captcha';

const { token, nonce } = await obtainPow();   // submit these with the form
```

React and Next.js get hooks instead:

```tsx
import { usePow } from '@gts-meghni/laravel-captcha/react';

const pow = usePow();   // pow.fields → { pow_token, pow_nonce }
```

In a FormRequest:

```php
use GtsMeghni\LaravelCaptcha\Validation\Rules\ProofOfWork;

'pow_token' => ['required', 'string'],
'pow_nonce' => ['required', 'string', new ProofOfWork],
```

### Difficulty follows suspicion

A single difficulty cannot serve both a first-time visitor and a script on its
five hundredth attempt. Two counters raise it, and both matter:

- **Failures.** Each recorded failure for a key adds `step` bits.
- **Requests.** Every `volume.every` challenges a key asks for adds `step` bits
  too. This is the one that hurts an AI-assisted attacker: its winning move is to
  discard a challenge it cannot read and ask for another, which is otherwise free.

| Failures | Difficulty | Cost to a person | Attacker throughput, one core |
| --- | --- | --- | --- |
| 0 | 16 | ~45ms, unnoticed | 443 solves/min |
| 1 | 18 | ~250ms | 111 solves/min |
| 2 | 20 | ~1s | 22 solves/min |
| 4 | 24 | ~17s | 1.4 solves/min |

```php
'pow' => [
    'enabled' => true,
    'difficulty' => 16,        // floor everyone pays
    'escalate' => true,
    'step' => 2,               // bits per recorded failure
    'max_difficulty' => 24,    // ceiling
    'volume' => [
        'every' => 20,         // challenges requested
        'step' => 2,           // bits added per that many
    ],
    'expire' => 120,
],
```

The difficulty issued with a challenge is stored alongside it, so raising the
floor mid-flight never invalidates work already done.

## Image challenge

```http
GET /api/captcha              issues a challenge
GET /api/captcha/{token}.png  streams the image, Cache-Control: no-store
```

```json
{
    "token": "IZ3aQ9vXk1sWpL7mNb2ThYcR8gJ4dF6uEoQwA0yV",
    "url": "https://example.test/api/captcha/IZ3aQ9vXk1sWpL7mNb2ThYcR8gJ4dF6uEoQwA0yV.png",
    "expires_in": 120,
    "expires_at": "2026-08-10T12:02:00+00:00"
}
```

`expires_in` is **seconds**, following RFC 6749; `expires_at` is the same moment
as an ISO-8601 stamp. Prefer `expires_in` for a countdown, because a device clock
that disagrees with the server cannot throw it off.

The image streams from a URL rather than being inlined as a `data:` URI, so a
front end under `img-src 'self'` can display it. It is drawn when requested, not
when the token is issued, and redrawn deterministically from a stored seed. A
refetch returns identical bytes, and a challenge never displayed costs no drawing
work at all.

```php
use GtsMeghni\LaravelCaptcha\Validation\Rules\Captcha;

'captcha_token' => ['required', 'string'],
'captcha' => ['required', 'string', new Captcha],
```

Or guard a route wholesale with the `captcha` middleware. Field names come from
config, so an existing payload needs no front-end change:

```php
'fields' => ['token' => 'recaptchaToken', 'answer' => 'userInput'],
```

One preset ships: `default`, six characters, case-insensitive. Register your own
challenge type on the manager and point a preset at it:

```php
app(ChallengeManager::class)->extend('words', new WordChallenge);

// config/captcha.php
'phrase' => ['type' => 'words', 'width' => 260],
```

### Single use, and only plausible answers

A token is single-use. Any verification consumes it, wrong answers included, and
consumption happens under a cache lock. Laravel's `pull()` is a `get()` followed
by a `forget()`, so without the lock two simultaneous submissions could both
spend one solved challenge.

Two further checks:

```php
'min_seconds' => 1.5,   // refuse answers faster than a person could give
'bind_ip' => false,     // require the answer from the address that asked
```

`min_seconds` costs nothing and removes scripts that answer in milliseconds. It
does not stop one that waits on purpose.

`bind_ip` closes the solving-service route, where a page forwards the image out
and the answer returns from another machine. It is **off by default** because a
phone moving from wifi to mobile data mid-form changes address and would be
refused through no fault of its own. Turn it on where clients are stable. It
applies to the proof of work as well.

### Behind a load balancer, configure TrustProxies first

Everything above that reads an address reads `$request->ip()`: `bind_ip`, the
proof-of-work binding, the throttles on all three endpoints, and the volume
counter when it is keyed on an address. Behind a proxy or load balancer with
Laravel's `TrustProxies` unconfigured, every request reports the *proxy's*
address instead of the client's.

That fails silently and in the wrong direction:

- `bind_ip` compares one shared address to itself, so it passes for everybody and
  protects nothing, while still reading as enabled.
- `throttle:60,1` becomes 60 requests a minute **for the whole site**, so one
  attacker locks out every real user.
- The volume counter climbs on aggregate traffic, so difficulty escalates for
  visitors who have done nothing.

Configure trusted proxies before turning `bind_ip` on, and confirm with
`Request::ip()` in a real request rather than assuming.

### Resisting OCR

Thresholding an image to strip its background is one operation and works on any
texture, so the package leans on distortion that cannot be separated from the
glyphs: `overlap` (touching characters defeat the segmentation OCR runs before
recognition), `wave` (sine shear across the finished image, GD only), `speckle`
with `tone: ink` (drawn from the glyph palette, so a luminance threshold cannot
drop it without dropping the glyphs), and per-glyph `angle` and `jitter`.

Prefer adding characters over adding distortion. More glyphs multiply the error
rate without making any single character harder for a person.

### Previewing a preset

Settings are easier to judge by eye than by reading. `captcha:preview` writes
samples to disk without a browser or a running front end:

```bash
php artisan captcha:preview --preset=default --count=5

  INFO Wrote 5 default sample(s) to /app/storage/app/captcha-preview.
```

`--path` writes somewhere else. Each run redraws from fresh seeds, so a handful
of samples shows the spread a preset produces rather than one lucky draw.

The answers are not printed, because only their digests are stored. Drawing goes
through the same cache as a live challenge, so a preview that reports the
challenge expired is telling you the store is misconfigured.

## Escalating only when there is a reason

A captcha on every login taxes every legitimate user daily to inconvenience a bot
a vision model already defeats. Ask after a couple of failures instead:

```php
$key = 'login:'.$request->input('email');

$rules = [
    'pow_token' => ['required', 'string'],
    'pow_nonce' => ['required', 'string', new ProofOfWork],
];

if (Captcha::requiredFor($key)) {
    $rules['captcha_token'] = ['required', 'string'];
    $rules['captcha'] = ['required', 'string', new Captcha];
}

$request->validate($rules);

Captcha::recordFailure($key);   // wrong password
Captcha::clearFailures($key);   // correct password, clears both counters
```

Return `'captcha_required' => Captcha::requiredFor($key)` from a failed attempt so
the front end knows to render the image next time rather than guessing.

```php
'escalation' => [
    'after' => 2,     // failures tolerated before a challenge is demanded
    'decay' => 900,   // seconds a failure is remembered
],
```

Key on the identity under attack, not the address it arrives from: an office
behind one NAT shares an IP, and a botnet does not. The same counter raises the
proof-of-work difficulty, so an attacker cannot dodge one defence without the
other.

## Storage

A challenge lives in the cache for `expire` seconds and holds only the preset
name, a render seed, a SHA-256 digest of the answer, the issue time and
optionally the issuing address. That is a couple of hundred bytes, and no table
of its own. `cache.store` defaults to the application's default store. Two requirements
decide whether a store works:

| | |
| --- | --- |
| **Shared across workers** | Required. The token is issued by one request and the image drawn by another. An `array` store, or a `file` store local to one container, hands out URLs that 404 on the next request. |
| **Expires on its own** | Preferred. Laravel removes an expired entry only when something reads it, and an abandoned challenge is never read again. |

Redis evicts by TTL and leaves nothing behind. The `file` and `database` stores
accumulate one entry per abandoned challenge, files against an inode quota and
rows in the cache table, so schedule the sweep:

```php
Schedule::command('captcha:prune')->hourly();
```

```bash
php artisan captcha:prune

  INFO Removed 209 expired entries from the [file] store.
```

A no-op on Redis and memcached, so it is safe to schedule everywhere. On the
database store it deletes only rows carrying the captcha prefix; on a file store
it sweeps every expired entry in that directory, because a file store hashes its
keys and entries cannot be attributed, which is the reason to give the captcha a
store of its own.

> Prefer a dedicated store or connection over sharing the application's. A
> `php artisan cache:clear` on a shared store deletes every in-flight challenge,
> and every user mid-form is told their answer is wrong.

## Localisation

English, French and Arabic ship for the validation messages. The challenge itself
never localises. Latin characters and Western digits in every locale, so one
keyboard layout answers any challenge.

## Measured results

Numbers, not intentions. Re-run these after any change to rendering or difficulty.

**Against Tesseract 5.3.4**, 100 challenges, attacked raw, then greyscaled and
thresholded and upscaled 3×, then again with a character whitelist and single-line
page mode:

| Corpus | Exact match | Per-character |
| --- | --- | --- |
| Control, every defence off, black on white | 40/50 (80%) | 88% |
| `default` | 0/100 | 3.3% |

The control proves the measurement: the same renderer with defences off is read at
80%, so the zeros describe the defences rather than a broken harness. Read them as
"below roughly 3%", since zero of 100 puts the 95% upper bound near there.

**Against a multimodal model**, 10 challenges read blind, answers committed
before scoring:

| Corpus | Exact match | Per-character |
| --- | --- | --- |
| `default` | 4/10 (40%) | 87% |

This is the number that matters, and why proof of work leads: classic OCR is
defeated by the image, a vision model is not.

Note too that a bot can discard a low-confidence read and ask for another
challenge, so effective accuracy approaches 100% given free retries, which is
what `pow.volume` is there to price.

**Attacker throughput**, one CPU core, plain JavaScript:

| Difficulty | Solves per minute |
| --- | --- |
| 16 | 443 |
| 20 | 22 |
| 24 | 1.4 |

At a flat 16 bits the endpoint throttle (60/min) binds long before the work does,
which is precisely why difficulty escalates.

## Limits

- A vision model reads the image challenge. Treat it as a filter for cheap
  automation, not a wall.
- Proof of work prices attempts; it does not identify humans. An attacker who
  reimplements the hash natively or on a GPU is not slowed at browser-friendly
  difficulties.
- Escalation keys on whatever you pass. An attack spread across many accounts, or
  many addresses, starts each key at the floor.
- Nothing here replaces per-account lockout, anomaly detection, or requiring a
  scarce credential where fake accounts are the concern.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Captcha! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [GTS-MEGHNI](https://github.com/GTS-MEGHNI)
- [All Contributors](../../contributors)

## License

Laravel Captcha is open-sourced software licensed under the [MIT license](LICENSE.md).
