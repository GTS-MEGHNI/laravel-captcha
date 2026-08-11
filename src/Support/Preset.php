<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Support;

use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;

/**
 * An immutable snapshot of one preset's settings.
 *
 * Presets are resolved once per request and passed down to the generator and
 * the renderer, so nothing writes settings back onto a shared service.
 */
class Preset
{
    /**
     * @param  list<string>  $characters
     * @param  list<string>  $fontColors
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $speckle
     * @param  array<string, mixed>  $wave
     * @param  array<string, mixed>  $options  Settings specific to the challenge type.
     */
    final public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $length,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $sensitive,
        public readonly array $characters,
        public readonly array $fontColors,
        public readonly array $background,
        public readonly array $speckle,
        public readonly int $angle,
        public readonly float $jitter,
        public readonly float $overlap,
        public readonly array $wave,
        public readonly int $padding,
        public readonly int $lines,
        public readonly int $lineWidth,
        public readonly ?string $lineColor,
        public readonly int $contrast,
        public readonly int $sharpen,
        public readonly int $blur,
        public readonly bool $invert,
        public readonly array $options = [],
    ) {}

    /**
     * Build a preset from the package config.
     *
     * Every named preset is merged over `default`, so a preset only declares
     * what it changes.
     *
     * @param  array<string, mixed>  $config
     */
    public static function resolve(array $config, string $name = 'default'): self
    {
        $defaults = Value::map($config['default'] ?? null);

        if ($name === 'default') {
            $settings = $defaults;
        } else {
            if (! is_array($config[$name] ?? null)) {
                throw CaptchaException::unknownPreset($name);
            }

            $overrides = Value::map($config[$name]);

            $settings = array_replace($defaults, $overrides);

            // Nested groups merge key by key, so a preset can change one dial
            // without restating the rest of its group.
            foreach (['background', 'speckle', 'wave'] as $nested) {
                if (is_array($defaults[$nested] ?? null) && is_array($overrides[$nested] ?? null)) {
                    $settings[$nested] = array_replace(
                        Value::map($defaults[$nested]),
                        Value::map($overrides[$nested]),
                    );
                }
            }
        }

        $known = [
            'type', 'length', 'width', 'height', 'sensitive', 'background', 'speckle', 'font_colors',
            'angle', 'jitter', 'overlap', 'wave', 'padding', 'lines', 'line_width', 'line_color',
            'contrast', 'sharpen', 'blur', 'invert',
        ];

        return new self(
            name: $name,
            type: Value::string($settings['type'] ?? null, 'text'),
            length: max(1, Value::int($settings['length'] ?? null, 6)),
            width: max(1, Value::int($settings['width'] ?? null, 160)),
            height: max(1, Value::int($settings['height'] ?? null, 46)),
            sensitive: Value::bool($settings['sensitive'] ?? null),
            characters: Value::strings($config['characters'] ?? null),
            fontColors: Value::strings($settings['font_colors'] ?? null),
            background: Value::map($settings['background'] ?? null),
            speckle: Value::map($settings['speckle'] ?? null),
            angle: Value::int($settings['angle'] ?? null, 15),
            jitter: max(0.0, Value::float($settings['jitter'] ?? null)),
            overlap: min(0.6, max(0.0, Value::float($settings['overlap'] ?? null))),
            wave: Value::map($settings['wave'] ?? null),
            padding: Value::int($settings['padding'] ?? null, 4),
            lines: max(0, Value::int($settings['lines'] ?? null)),
            lineWidth: max(1, Value::int($settings['line_width'] ?? null, 1)),
            lineColor: Value::nullableString($settings['line_color'] ?? null),
            contrast: Value::int($settings['contrast'] ?? null),
            sharpen: Value::int($settings['sharpen'] ?? null),
            blur: Value::int($settings['blur'] ?? null),
            invert: Value::bool($settings['invert'] ?? null),
            options: array_diff_key(Value::map($settings), array_flip($known)),
        );
    }

    /**
     * A setting specific to the challenge type.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
