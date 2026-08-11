<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Tests\TestCase;

// The type coverage plugin analyses source files in forked workers, and every
// worker rewrites the same PHPStan result cache inside the plugin's vendor
// directory. On multi-core CI runners those writes interleave and the cache is
// later re-read as broken PHP ("syntax error, unexpected single-quoted string").
// Pokio sizes its worker pool from the assumed memory per process, so claiming
// the whole address space per worker keeps the analysis on a single worker and
// the cache writes serialized.
putenv('FORK_MEM_PER_PROC='.PHP_INT_MAX);

uses(TestCase::class)->in(__DIR__);
