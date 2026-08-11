<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Challenges;

use GtsMeghni\LaravelCaptcha\Support\Preset;

interface ChallengeGenerator
{
    /**
     * Produce the glyphs to draw and the answer to expect.
     *
     * Implementations must draw the answer from a cryptographic source such as
     * random_int(); the render seed is for appearance only.
     */
    public function generate(Preset $preset): Challenge;
}
