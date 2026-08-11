# Release Notes

## [Unreleased](https://github.com/GTS-MEGHNI/laravel-captcha/compare/v1.0.0...main)

## [v1.0.0](https://github.com/GTS-MEGHNI/laravel-captcha/releases/tag/v1.0.0) - 2026-08-11

Initial release.

### Added

- Invisible proof of work: `GET /api/captcha/pow` issues a server-chosen salt and
  difficulty, and the `ProofOfWork` rule verifies a submitted nonce with a single
  hash. Nothing is shown to the user.
- Difficulty escalates per key, from recorded failures and from the number of
  challenges requested, so retrying until a challenge is readable stops being free.
- `Captcha::requiredFor()`, `recordFailure()` and `clearFailures()`, so a form can
  stay clean until a caller has started failing.
- `min_seconds`, refusing answers that arrive faster than a person could give them.
- `bind_ip`, optionally requiring an answer from the address that asked. Off by
  default: a phone moving between networks changes address mid-form.
- `captcha:prune`, sweeping expired challenges from stores that do not evict on
  their own. A no-op on Redis and memcached, so it is safe to schedule everywhere.
- `captcha:preview`, writing sample images to disk for judging a rendering change.
- French and Arabic translations alongside English.
- Challenge images stream from a URL rather than being inlined as base64, so a
  front end under `img-src 'self'` can display them.
- Images are drawn when requested rather than when issued, and redrawn
  deterministically from a stored seed. Rendering goes through
  `intervention/image` ^4.2, which the package requires.
- Tokens are consumed under a cache lock, because `Cache::pull()` is a `get()`
  followed by a `forget()` and two simultaneous submissions could otherwise both
  spend one solved challenge.
- Routes are served under `api/captcha` by default, and the response reports both
  `expires_in` (seconds, per RFC 6749) and `expires_at`.

### Notes

- There is no arithmetic challenge, deliberately. A multimodal model read one 6
  times out of 6, and its answer space is small enough to guess at roughly 1 in
  199.
- The browser half is a separate package, `@gts-meghni/laravel-captcha`
  on npm.
