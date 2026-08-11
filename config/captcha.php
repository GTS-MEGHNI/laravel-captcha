<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Governs the image challenge only. When disabled, the Captcha rule and the
    | captcha middleware pass every request through without inspecting the
    | answer. Image generation keeps working so local and staging front-ends
    | still render something.
    |
    | The proof of work has its own switch at `pow.enabled`, so this is not a
    | global off switch: setting it to false leaves the ProofOfWork rule
    | enforcing, which is usually what you want, since the invisible half costs
    | nobody anything in development.
    |
    | Published copies of this file are free to read from env(); the packaged
    | copy stays literal so a cached config never resolves it to null.
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Image driver
    |--------------------------------------------------------------------------
    |
    | Either "gd" or "imagick". Only used when the application has not already
    | bound its own Intervention ImageManager.
    |
    */

    'driver' => 'gd',

    /*
    |--------------------------------------------------------------------------
    | Request fields
    |--------------------------------------------------------------------------
    |
    | Field names the middleware and validation rule read from the request.
    | Point these at your existing payload to avoid a front-end change.
    |
    */

    'fields' => [
        'token' => 'captcha_token',
        'answer' => 'captcha',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plausibility
    |--------------------------------------------------------------------------
    |
    | Reading six distorted characters and typing them takes a person at least a
    | second; a script answers in tens of milliseconds. Answers that arrive
    | sooner than this are refused. It does not stop a script that waits on
    | purpose, and it costs nothing.
    |
    | Set to 0 to accept any timing.
    |
    */

    'min_seconds' => 1.5,

    /*
    |--------------------------------------------------------------------------
    | Bind a challenge to the address that asked for it
    |--------------------------------------------------------------------------
    |
    | Without this, a challenge issued to a visitor can be answered from
    | anywhere, which is how a solving service works: the page sends the image
    | out, and the answer comes back from another machine. With it, the answer
    | must arrive from the same address.
    |
    | Off by default on purpose. A phone that moves from wifi to mobile data
    | mid-form changes address and would be refused through no fault of its own.
    | Turn it on where clients are stable, and applies to both the image and the
    | proof of work.
    |
    */

    'bind_ip' => false,

    /*
    |--------------------------------------------------------------------------
    | Challenge storage
    |--------------------------------------------------------------------------
    |
    | Challenges live in the cache for `expire` seconds. Only the answer hash,
    | the render seed and the preset name are stored, so a row stays around a
    | hundred bytes rather than holding the encoded image.
    |
    | The store must be shared by every worker that serves traffic. `array` and
    | a per-container `file` store will hand out image URLs that 404 elsewhere.
    |
    */

    'cache' => [
        'store' => null,
        'prefix' => 'captcha',
        'expire' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    |
    | Demand the image challenge only after a key has failed `after` times, and
    | forget those failures after `decay` seconds. Used through
    | Captcha::requiredFor(), so the policy stays in the application where the
    | login logic lives.
    |
    | Key on the identity under attack rather than the address it comes from. An
    | office behind one NAT shares an IP; a botnet does not.
    |
    */

    'escalation' => [
        'after' => 2,
        'decay' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Proof of work
    |--------------------------------------------------------------------------
    |
    | The client must find a nonce where sha256(salt + nonce) opens with
    | `difficulty` zero bits. There is nothing to look at, so nothing a vision
    | model can read; what it costs an attacker is CPU per attempt.
    |
    | difficulty is in bits, so each step doubles the expected work. Solve time
    | is geometrically distributed, not fixed: at 16 bits the median is about
    | 45ms in a desktop browser but one attempt in a hundred takes ten times
    | that, and a phone is three to five times slower again. Measured at 18 bits
    | on a desktop: 116ms, 175ms — and 1437ms. Raise it only against measured
    | numbers on the slowest device you support.
    |
    | Be clear about what this buys. It stops scripted automation, which pays the
    | same cost per attempt as a browser, and it makes bulk abuse arithmetic
    | rather than free. It does not stop an attacker who reimplements the hash
    | natively or on a GPU, where these difficulties are seconds of work for a
    | hundred thousand attempts. It does not identify humans at all.
    |
    | Per-identity attempt limits remain the defence that actually caps a
    | determined attacker. This prices volume; that stops focus.
    |
    */

    'pow' => [
        'enabled' => true,

        // What a first-time visitor pays. Measured: ~45ms median in a desktop
        // browser, and one CPU core solves about 443 a minute — so on its own
        // this filters clients that do not run the hash at all, and little else.
        'difficulty' => 16,

        /*
        | Difficulty follows suspicion.
        |
        | A fixed difficulty has to serve both a first-time visitor and a script
        | on its five hundredth attempt, and cannot suit both: the setting that
        | slows the script makes a phone wait seconds. So each recorded failure
        | for the key adds `step` bits, up to `max_difficulty`.
        |
        | Measured, one core of plain JavaScript:
        |
        |   16 bits  ~45ms for a human    443 solves/min for an attacker
        |   20 bits  ~1s                   22 solves/min
        |   24 bits  ~17s                  1.4 solves/min
        |
        | A normal user stays at the floor and never notices. A credential
        | stuffing run climbs within seconds and is capped by arithmetic rather
        | than by policy, and cannot shed the counter by taking a fresh token,
        | because it keys on the identity being attacked.
        */
        'escalate' => true,
        'step' => 2,
        'max_difficulty' => 24,

        /*
        | Failures are not the only signal worth pricing. A script hunting for a
        | challenge it can read never fails — it discards and asks again — so
        | every `every` requests from a key adds `step` bits as well. That is
        | what makes "try again until I am confident" stop being free.
        */
        'volume' => [
            'every' => 20,
            'step' => 2,
        ],

        'expire' => 120,
        'fields' => [
            'token' => 'pow_token',
            'nonce' => 'pow_nonce',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | GET {prefix}            issues a challenge: { token, url, expires_in }
    | GET {prefix}/{token}.png streams the image for that token
    |
    | The prefix carries the api segment itself. These routes are registered by
    | the package rather than from the application's routes/api.php, so nothing
    | else prepends it.
    |
    | Both endpoints are unauthenticated by nature, so both are throttled.
    | Set `enabled` to false to register neither and drive the facade yourself.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/captcha',
        'middleware' => ['api'],
        'name' => 'captcha',
        // The limits are per IP, and an office behind a single NAT shares one.
        // Too tight locks out real users; these leave room for a page that
        // issues a challenge on load and a user who retries a few times.
        'throttle' => [
            'issue' => '60,1',
            'image' => '120,1',
            'pow' => '60,1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | Directory scanned for `.ttf` files. The bundled faces are licensed under
    | OFL, Apache-2.0 and the Ubuntu Font Licence; see resources/fonts/license.
    |
    */

    'fonts_path' => __DIR__.'/../resources/fonts',

    /*
    |--------------------------------------------------------------------------
    | Background images
    |--------------------------------------------------------------------------
    |
    | Directory scanned when a preset sets its background mode to "images".
    |
    */

    'backgrounds_path' => __DIR__.'/../resources/backgrounds',

    /*
    |--------------------------------------------------------------------------
    | Character pool
    |--------------------------------------------------------------------------
    |
    | Glyphs that are easily confused for one another are left out on purpose:
    | 0/O, 1/l/I, 5/S, plus the low-legibility k, v, w and s.
    |
    */

    'characters' => [
        '2', '3', '4', '6', '7', '8', '9',
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'm', 'n', 'p', 'q', 'r', 't', 'u', 'x', 'y', 'z',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'M', 'N', 'P', 'Q', 'R', 'T', 'U', 'X', 'Y', 'Z',
    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Every preset inherits from `default`. `type` selects a registered challenge
    | generator; `text` ships, and others can be registered on ChallengeManager.
    |
    */

    'default' => [
        'type' => 'text',

        'length' => 6,
        'width' => 180,
        'height' => 46,
        'sensitive' => false,

        'background' => [
            // images   scaled from backgrounds_path
            // generated drawn at the exact canvas size, see style
            // solid    a flat fill of color
            'mode' => 'images',
            'style' => 'noise',         // noise | mesh | blobs, for mode: generated
            'color' => '#ecf2f4',
            'path' => null,             // overrides backgrounds_path for this preset
        ],

        /*
        | Speckle sits on top of whichever background was chosen, so it applies
        | to the bundled images too.
        |
        | density is the share of pixels covered. 'ink' draws from font_colors,
        | which survives the luminance threshold a bot applies first; 'light'
        | is the pale decorative texture, which does not.
        */
        'speckle' => [
            'density' => 0.012,
            'tone' => 'ink',            // ink | light | none
        ],

        'font_colors' => ['#2c3e50', '#c0392b', '#16a085', '#8e44ad', '#303f9f', '#f57c00', '#795548'],
        'padding' => 4,

        // Rotation, vertical wander and horizontal encroachment. Overlap is the
        // strongest of the three: touching glyphs defeat the segmentation step
        // that OCR runs before it recognises anything.
        'angle' => 18,
        'jitter' => 0.10,
        'overlap' => 0.08,

        // Sinusoidal column shear applied after drawing. Warps glyph and
        // background together so neither can be separated out. GD only.
        'wave' => [
            'amplitude' => 2,
            'period' => 45,
        ],

        // Lines are forced through the band the glyphs occupy, in glyph colours
        // and glyph weight, so a connected-component pass cannot drop them.
        'lines' => 2,
        'line_width' => 2,
        'line_color' => null,           // null picks from font_colors

        'contrast' => -5,
        'sharpen' => 0,
        'blur' => 0,
        'invert' => false,
    ],

];
