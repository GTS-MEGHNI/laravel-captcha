<?php

declare(strict_types=1);

use GtsMeghni\LaravelCaptcha\Rendering\BackgroundPainter;
use GtsMeghni\LaravelCaptcha\Rendering\Renderer;
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

/*
| The asset directories the provider handed to the renderer and the painter.
| Read from the resolved objects rather than from config, because the config
| values are nullable and the fallback is the thing under test.
|
| Both instances are forgotten first, so a test may set the config and then ask
| what the binding resolves to.
*/
function fontsPath(): string
{
    app()->forgetInstance(Renderer::class);

    return resolvedPath(app(Renderer::class), 'fontsPath');
}

function backgroundsPath(): string
{
    app()->forgetInstance(BackgroundPainter::class);
    app()->forgetInstance(Renderer::class);

    return resolvedPath(app(BackgroundPainter::class), 'backgroundsPath');
}

function resolvedPath(object $target, string $property): string
{
    $value = (new ReflectionProperty($target, $property))->getValue($target);

    return is_string($value) ? $value : '';
}
