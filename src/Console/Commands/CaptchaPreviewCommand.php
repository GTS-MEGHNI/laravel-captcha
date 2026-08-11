<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Console\Commands;

use GtsMeghni\LaravelCaptcha\Captcha;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes sample images to disk so legibility can be judged without a browser.
 */
class CaptchaPreviewCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'captcha:preview
        {--preset=default : The preset to render}
        {--count=3 : How many samples to write}
        {--path= : Directory to write into, defaults to storage/app/captcha-preview}';

    /**
     * The command description.
     */
    protected $description = 'Render sample captcha images for the given preset.';

    public function handle(Captcha $captcha, Filesystem $files): int
    {
        $option = $this->option('preset');
        $preset = is_string($option) && $option !== '' ? $option : 'default';

        $count = max(1, (int) $this->option('count'));

        $option = $this->option('path');

        $path = is_string($option) && $option !== ''
            ? $option
            : storage_path('app/captcha-preview');

        $files->ensureDirectoryExists($path);

        for ($i = 1; $i <= $count; $i++) {
            $issued = $captcha->create($preset);
            $png = $captcha->image($issued->token);

            if ($png === null) {
                $this->components->error('The challenge expired before it could be drawn. Check the captcha cache store.');

                return self::FAILURE;
            }

            $file = $path.'/'.$preset.'-'.$i.'.png';
            $files->put($file, $png);

            $this->components->twoColumnDetail($file, strlen($png).' bytes');
        }

        $this->newLine();
        $this->components->info("Wrote {$count} {$preset} sample(s) to {$path}.");
        $this->line('  Answers are not printed: they are stored as digests only.');

        return self::SUCCESS;
    }
}
