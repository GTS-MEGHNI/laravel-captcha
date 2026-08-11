<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Challenges;

use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use GtsMeghni\LaravelCaptcha\Support\Preset;
use Illuminate\Support\Str;

/**
 * A run of random characters, drawn as-is.
 */
class TextChallenge implements ChallengeGenerator
{
    public function generate(Preset $preset): Challenge
    {
        $pool = $preset->characters;

        if ($pool === []) {
            throw CaptchaException::emptyCharacterPool();
        }

        $glyphs = [];

        for ($i = 0; $i < $preset->length; $i++) {
            $glyphs[] = $pool[random_int(0, count($pool) - 1)];
        }

        /** @var non-empty-list<string> $glyphs */
        $answer = implode('', $glyphs);

        return new Challenge(
            glyphs: $glyphs,
            answer: $preset->sensitive ? $answer : Str::lower($answer),
        );
    }
}
