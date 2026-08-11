<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Console\Commands;

use GtsMeghni\LaravelCaptcha\Store\ChallengePruner;
use Illuminate\Console\Command;

/**
 * Sweeps expired challenges from stores that do not evict on their own.
 */
class CaptchaPruneCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'captcha:prune';

    /**
     * The command description.
     */
    protected $description = 'Remove expired captcha challenges from the cache store.';

    public function handle(ChallengePruner $pruner): int
    {
        $result = $pruner->prune();

        if (! $result->supported) {
            $this->components->info("Nothing to prune: the [{$result->driver}] store expires its own entries.");

            return self::SUCCESS;
        }

        $this->components->info("Removed {$result->removed} expired entries from the [{$result->driver}] store.");

        if (! $result->scoped) {
            $this->components->warn(
                'A file store hashes its keys, so every expired entry in that directory was swept, not only captcha entries.',
            );
        }

        return self::SUCCESS;
    }
}
