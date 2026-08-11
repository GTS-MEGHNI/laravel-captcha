<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Challenges;

/**
 * A generated challenge: what gets drawn, and what the user must type.
 *
 * `glyphs` is always a list of short strings so the renderer can space them
 * out one at a time, and a glyph may be more than one character. `answer` is what
 * the user is expected to submit, which a generator is free to make different
 * from what appears in the image.
 */
class Challenge
{
    /**
     * @param  non-empty-list<string>  $glyphs
     */
    final public function __construct(
        public readonly array $glyphs,
        public readonly string $answer,
    ) {}
}
