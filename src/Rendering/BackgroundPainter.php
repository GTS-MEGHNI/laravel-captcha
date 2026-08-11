<?php

/** @noinspection PhpPipeOperatorCanBeUsedInspection */

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Rendering;

use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use GtsMeghni\LaravelCaptcha\Support\Preset;
use GtsMeghni\LaravelCaptcha\Support\Seed;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Filesystem\Filesystem;
use Intervention\Image\Geometry\Factories\EllipseFactory;
use Intervention\Image\Geometry\Factories\LineFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use SplFileInfo;

/**
 * Paints the layer the glyphs sit on.
 *
 * Two independent jobs. The base is decorative — a scaled bitmap, a generated
 * texture or a flat fill. The speckle on top is defensive: drawn in the glyph
 * palette, so thresholding the image to strip the background cannot remove it
 * without taking the glyphs with it.
 */
class BackgroundPainter
{
    /**
     * Resolved image paths, keyed by directory.
     *
     * @var array<string, non-empty-list<string>>
     */
    protected array $images = [];

    public function __construct(
        protected Filesystem $files,
        protected ImageManager $manager,
        protected string $backgroundsPath = '',
    ) {}

    public function paint(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $mode = Value::string($preset->background['mode'] ?? null, 'generated');

        $image->fill(Value::string($preset->background['color'] ?? null, '#ffffff'));

        match ($mode) {
            'images' => $this->placeImage($image, $preset, $seed),
            'solid' => null,
            default => $this->generate($image, $preset, $seed),
        };
    }

    /**
     * Scatter dots over whatever the base painted.
     *
     * Applied after the base and before the glyphs, so the speckle reads as
     * part of the texture rather than as marks over the characters.
     */
    public function speckle(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $tone = Value::string($preset->speckle['tone'] ?? null, 'light');

        $density = Value::float($preset->speckle['density'] ?? null);

        if ($tone === 'none' || $density <= 0.0) {
            return;
        }

        $dots = (int) ($preset->width * $preset->height * min(0.4, $density));

        /** @var non-empty-list<string> $ink */
        $ink = $preset->fontColors === [] ? ['#333333'] : $preset->fontColors;

        for ($i = 0; $i < $dots; $i++) {
            $image->drawPixel(
                $seed->between(0, $preset->width - 1),
                $seed->between(0, $preset->height - 1),
                $tone === 'ink' ? $seed->pick($ink) : $this->shade($seed, 200, 245),
            );
        }
    }

    /**
     * Scale one of the configured background images to fill the canvas.
     */
    protected function placeImage(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $path = Value::string($preset->background['path'] ?? null);

        $image->insert(
            $this->manager
                ->decodePath($seed->pick($this->availableImages($path !== '' ? $path : $this->backgroundsPath)))
                ->resize($preset->width, $preset->height),
        );
    }

    /**
     * @return non-empty-list<string>
     */
    protected function availableImages(string $path): array
    {
        if (isset($this->images[$path])) {
            return $this->images[$path];
        }

        $candidates = $path !== '' && $this->files->isDirectory($path)
            ? array_values(array_filter(
                array_map(
                    static fn (SplFileInfo $file): string => $file->getPathname(),
                    $this->files->files($path),
                ),
                static fn (string $file): bool => in_array(
                    strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                    ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'],
                    true,
                ),
            ))
            : [];

        if ($candidates === []) {
            throw CaptchaException::noBackgrounds($path);
        }

        return $this->images[$path] = $candidates;
    }

    /**
     * Draw a background from the seed.
     */
    protected function generate(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $style = Value::string($preset->background['style'] ?? null, 'noise');

        match ($style) {
            'mesh' => $this->mesh($image, $preset, $seed),
            'blobs' => $this->blobs($image, $preset, $seed),
            default => $this->grain($image, $preset, $seed),
        };
    }

    /**
     * A pale scanned-paper grain.
     */
    protected function grain(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $dots = (int) ($preset->width * $preset->height * 0.06);

        for ($i = 0; $i < $dots; $i++) {
            $image->drawPixel(
                $seed->between(0, $preset->width - 1),
                $seed->between(0, $preset->height - 1),
                $this->shade($seed, 200, 245),
            );
        }
    }

    /**
     * Long crossing strokes at a low contrast.
     */
    protected function mesh(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $strokes = max(6, (int) ($preset->width / 12));

        for ($i = 0; $i < $strokes; $i++) {
            $y = $seed->between(0, $preset->height);

            $image->drawLine(function (LineFactory $line) use ($preset, $seed, $y): void {
                $line->from($seed->between(-10, $preset->width), $y);
                $line->to($seed->between(0, $preset->width + 10), $seed->between(0, $preset->height));
                $line->color($this->shade($seed, 195, 235));
                $line->width($seed->between(1, 2));
            });
        }
    }

    /**
     * Overlapping soft discs.
     */
    protected function blobs(ImageInterface $image, Preset $preset, Seed $seed): void
    {
        $count = max(5, (int) ($preset->width / 18));

        for ($i = 0; $i < $count; $i++) {
            $diameter = $seed->between((int) ($preset->height / 3), $preset->height);

            $x = $seed->between(0, $preset->width);
            $y = $seed->between(0, $preset->height);

            $image->drawEllipse(function (EllipseFactory $ellipse) use ($x, $y, $diameter, $seed): void {
                $ellipse->at($x, $y);
                $ellipse->size($diameter, $diameter);
                $ellipse->background($this->shade($seed, 205, 240));
            });
        }
    }

    /**
     * A light gray with a slight, seeded color cast.
     */
    protected function shade(Seed $seed, int $min, int $max): string
    {
        $level = $seed->between($min, $max);

        return sprintf(
            '#%02x%02x%02x',
            min(255, $level + $seed->between(0, 6)),
            min(255, $level + $seed->between(0, 6)),
            min(255, $level + $seed->between(0, 6)),
        );
    }
}
