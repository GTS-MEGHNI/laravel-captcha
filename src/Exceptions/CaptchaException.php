<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Exceptions;

use RuntimeException;

class CaptchaException extends RuntimeException
{
    public static function unknownPreset(string $name): self
    {
        return new self("The captcha preset [{$name}] is not defined in config/captcha.php.");
    }

    public static function unknownChallengeType(string $type): self
    {
        return new self("No captcha challenge generator is registered for the type [{$type}].");
    }

    public static function noFonts(string $path): self
    {
        return new self("No .ttf fonts were found in [{$path}]. Set captcha.fonts_path to a directory containing at least one font, or to null for the fonts bundled with the package.");
    }

    public static function noBackgrounds(string $path): self
    {
        return new self("No background images were found in [{$path}]. Add images, set captcha.backgrounds_path to null for the images bundled with the package, or set the background mode to [generated].");
    }

    public static function emptyCharacterPool(): self
    {
        return new self('The captcha character pool is empty. Populate captcha.characters.');
    }
}
