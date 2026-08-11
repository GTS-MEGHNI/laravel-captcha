<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Rendering;

use GdImage;
use GtsMeghni\LaravelCaptcha\Challenges\Challenge;
use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use GtsMeghni\LaravelCaptcha\Support\Preset;
use GtsMeghni\LaravelCaptcha\Support\Seed;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Filesystem\Filesystem;
use Intervention\Image\Alignment;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\LineFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use SplFileInfo;

class InterventionRenderer implements Renderer
{
    /**
     * Resolved font paths, keyed by directory.
     *
     * @var array<string, non-empty-list<string>>
     */
    protected array $fonts = [];

    public function __construct(
        protected ImageManager $manager,
        protected BackgroundPainter $backgrounds,
        protected Filesystem $files,
        protected string $fontsPath,
    ) {}

    public function render(Challenge $challenge, Preset $preset, Seed $seed): string
    {
        $image = $this->manager->createImage($preset->width, $preset->height);

        $this->backgrounds->paint($image, $preset, $seed);

        if ($preset->contrast !== 0) {
            $image->contrast($preset->contrast);
        }

        $this->backgrounds->speckle($image, $preset, $seed);

        $this->writeGlyphs($image, $challenge, $preset, $seed);
        $this->drawLines($image, $preset, $seed);
        $this->warp($image, $preset, $seed);

        if ($preset->sharpen > 0) {
            $image->sharpen($preset->sharpen);
        }

        if ($preset->blur > 0) {
            $image->blur($preset->blur);
        }

        if ($preset->invert) {
            $image->invert();
        }

        return (string) $image->encode(new PngEncoder);
    }

    /**
     * Space the glyphs across the canvas, one draw call each.
     *
     * Slots are measured against the number of glyphs actually produced rather
     * than the preset length, because a generator is free to emit a different
     * count from the one configured.
     *
     * The preset's overlap pulls the slots closer together than their own width
     * so neighbouring glyphs touch. That is deliberate: OCR segments an image
     * into candidate characters before recognising any of them, and connected
     * glyphs defeat that first step.
     */
    protected function writeGlyphs(ImageInterface $image, Challenge $challenge, Preset $preset, Seed $seed): void
    {
        $count = count($challenge->glyphs);

        $usable = $preset->width - ($preset->padding * 2);
        $advance = ($usable / $count) * (1 - $preset->overlap);

        // Re-centre whatever width the run collapsed to.
        $left = ($preset->width - ($advance * ($count - 1))) / 2;

        $band = (int) ($preset->height * $preset->jitter);

        foreach ($challenge->glyphs as $index => $glyph) {
            $x = (int) ($left + ($index * $advance));
            $y = (int) ($preset->height / 2) + ($band > 0 ? $seed->between(-$band, $band) : 0);

            $size = $seed->between(
                (int) ($preset->height * 0.62),
                (int) ($preset->height * 0.86),
            );

            $font = $this->font($seed);
            $color = $this->fontColor($preset, $seed);
            $angle = $seed->between(-$preset->angle, $preset->angle);

            $image->text($glyph, $x, $y, function (FontFactory $settings) use ($font, $size, $color, $angle): void {
                $settings->file($font);
                $settings->size($size);
                $settings->color($color);
                // Both axes centre on the point: v4 folded v3's separate
                // `valign('middle')` into the second argument, and dropped
                // `middle` in favour of `center`.
                $settings->align(Alignment::CENTER, Alignment::CENTER);
                $settings->angle($angle);
            });
        }
    }

    /**
     * Strike lines through the glyphs.
     *
     * Both endpoints stay inside the band the glyphs occupy, in a glyph colour
     * and at glyph weight, so the lines cannot be told from the characters by
     * colour or thickness and cannot be discarded as background.
     */
    protected function drawLines(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $top = (int) ($preset->height * 0.2);
        $bottom = (int) ($preset->height * 0.8);

        for ($i = 0; $i < $preset->lines; $i++) {
            $color = $preset->lineColor ?? $this->fontColor($preset, $seed);

            $image->drawLine(function (LineFactory $line) use ($preset, $seed, $color, $top, $bottom): void {
                $line->from($seed->between(0, (int) ($preset->width * 0.3)), $seed->between($top, $bottom));
                $line->to($seed->between((int) ($preset->width * 0.7), $preset->width), $seed->between($top, $bottom));
                $line->color($color);
                $line->width($preset->lineWidth);
            });
        }
    }

    /**
     * Shear the finished image column by column along a sine curve.
     *
     * Glyphs, lines and background all move together, so no filter can undo the
     * distortion for the glyphs alone. GD only: the shear works on the raw
     * bitmap, so an Imagick-backed image is left undistorted rather than
     * throwing.
     */
    protected function warp(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $amplitude = Value::int($preset->wave['amplitude'] ?? null);
        $period = max(1, Value::int($preset->wave['period'] ?? null, 40));

        if ($amplitude <= 0) {
            return;
        }

        $frame = $image->core()->first();
        $source = $frame->native();

        if (! $source instanceof GdImage) {
            return;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $target = imagecreatetruecolor($width, $height);

        // Seed the target with the undistorted image so a shifted column never
        // exposes uninitialised black pixels at the top or bottom edge.
        imagecopy($target, $source, 0, 0, 0, 0, $width, $height);

        $phase = $seed->fraction() * M_PI * 2;

        for ($x = 0; $x < $width; $x++) {
            $offset = (int) round(sin(($x / $period * M_PI * 2) + $phase) * $amplitude);

            if ($offset !== 0) {
                imagecopy($target, $source, $x, $offset, $x, 0, 1, $height);
            }
        }

        $frame->setNative($target);
    }

    /**
     * @return non-empty-list<string>
     */
    protected function availableFonts(): array
    {
        if (isset($this->fonts[$this->fontsPath])) {
            return $this->fonts[$this->fontsPath];
        }

        $fonts = $this->files->isDirectory($this->fontsPath)
            ? array_values(array_filter(
                array_map(
                    static fn (SplFileInfo $file): string => $file->getPathname(),
                    $this->files->files($this->fontsPath),
                ),
                static fn (string $file): bool => strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'ttf',
            ))
            : [];

        if ($fonts === []) {
            throw CaptchaException::noFonts($this->fontsPath);
        }

        return $this->fonts[$this->fontsPath] = $fonts;
    }

    protected function font(Seed $seed): string
    {
        return $seed->pick($this->availableFonts());
    }

    protected function fontColor(Preset $preset, Seed $seed): string
    {
        if ($preset->fontColors === []) {
            return sprintf('#%02x%02x%02x', $seed->between(0, 120), $seed->between(0, 120), $seed->between(0, 120));
        }

        /** @var non-empty-list<string> $colors */
        $colors = $preset->fontColors;

        return $seed->pick($colors);
    }
}
