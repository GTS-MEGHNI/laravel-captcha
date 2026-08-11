---
name: laravel-captcha-development
description: >
  Configure and apply the Laravel Captcha package in Laravel applications.
license: MIT
metadata:
  author: GTS-MEGHNI
---

# Laravel Captcha

Use this skill when a Laravel application needs to integrate the Laravel Captcha package.

## Primary Goal

- apply the `gts-meghni/laravel-captcha` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- confirm the cache store is shared across workers (`redis`, `memcached`, `database`);
  an `array` store or a per-container `file` store issues image URLs that 404
- on a `file` or `database` store, confirm `captcha:prune` is scheduled; Laravel
  drops an expired entry only when something reads it, and an abandoned challenge
  is never read again
- inspect the target code paths where the package should be applied

### 2. Apply the package's public API

The package issues a challenge, streams its image from a URL, and verifies the
answer once. Nothing is inlined as a `data:` URI and nothing is persisted to the
database.

**Endpoints**, registered under `captcha.routes.prefix` unless
`captcha.routes.enabled` is `false`:

- `GET /api/captcha` returns `{ token, url, expires_in, expires_at }`.
  `expires_in` is in seconds (RFC 6749), `expires_at` is the ISO-8601 equivalent
- `GET /api/captcha/pow` returns `{ token, salt, difficulty, algorithm, ... }` for
  the invisible proof-of-work challenge, which is the one to prefer
- `GET /api/captcha/{token}.png` streams `image/png` with `Cache-Control: no-store`

**Verify in a FormRequest** by attaching the rule to the answer field:

```php
use GtsMeghni\LaravelCaptcha\Validation\Rules\Captcha;

'captcha_token' => ['required', 'string'],
'captcha' => ['required', 'string', new Captcha],
```

**Or guard the route** with the `captcha` middleware alias:

```php
Route::post('/login', LoginController::class)->middleware('captcha');
```

**Or drive it directly** when the app has its own response envelope:

```php
use GtsMeghni\LaravelCaptcha\Facades\Captcha;

$issued = Captcha::create();          // {token, url, expiresIn, expiresAt, preset}
$png = Captcha::image($issued->token); // encoded PNG, or null once expired
Captcha::verify($answer, $token);      // bool, consumes the token
```

**Match an existing payload** instead of renaming front-end fields:

```php
'fields' => ['token' => 'recaptchaToken', 'answer' => 'userInput'],
```

**Add a challenge type** by registering a `ChallengeGenerator` and pointing a
preset's `type` at it. Only `text` ships:

```php
app(ChallengeManager::class)->extend('words', new WordChallenge);
```

**Schedule the sweep** where the store does not evict on its own. The command is
a no-op on Redis and memcached, so it is safe to schedule everywhere:

```php
Schedule::command('captcha:prune')->hourly();
```

## Rules, References, and Templates

Read before executing:

- `config/captcha.php` for presets, fields, cache and route settings

Hold to these:

- a token is single-use; any verification attempt consumes it, wrong answers included
- presets inherit from `default`, so a preset declares only what it changes
- `captcha.enabled` false makes the rule and middleware pass everything through,
  while generation keeps working
- a custom `ChallengeGenerator` must draw its answer from `random_int()`, not from
  the render seed

## Examples

- an API login endpoint that issues a challenge on load, then validates
  `captcha_token` + `captcha` in the login FormRequest
- an SPA under `img-src 'self'` that renders `<img src="{url from GET /captcha}">`
- a login endpoint running proof of work alone, adding the image only once
  `Captcha::requiredFor($key)` turns true

## Anti-patterns

- do not inline the image as base64; the URL exists so a strict CSP still renders it
- do not store challenges in a table of their own; the cache holds them
- do not assume the cache TTL reclaims space: on `file` and `database` stores the
  expired entries stay until `captcha:prune` runs
- do not reuse a token after a failed attempt; issue a new challenge
- do not point `captcha.cache.store` at a store that is not shared by every worker
- do not share the application's cache store if a deploy runs `cache:clear`; it
  deletes every in-flight challenge and fails the users mid-form
- do not document package internals here; keep the skill focused on adoption in Laravel apps
