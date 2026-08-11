<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Rendering;

use GtsMeghni\LaravelCaptcha\Challenges\Challenge;
use GtsMeghni\LaravelCaptcha\Support\Preset;
use GtsMeghni\LaravelCaptcha\Support\Seed;

interface Renderer
{
    /**
     * Draw the challenge and return the encoded PNG bytes.
     *
     * The same seed must always produce the same bytes: an image is drawn when
     * it is requested, not when the challenge is issued, so a client that
     * refetches the URL has to receive the image it already has.
     */
    public function render(Challenge $challenge, Preset $preset, Seed $seed): string;
}
