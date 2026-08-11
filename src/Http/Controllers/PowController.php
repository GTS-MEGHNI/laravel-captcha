<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Http\Controllers;

use GtsMeghni\LaravelCaptcha\Pow\Pow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues proof-of-work challenges.
 */
class PowController
{
    public function __construct(protected Pow $pow) {}

    /**
     * Issue a challenge, priced by what this caller has been doing.
     *
     * The key is the request IP rather than anything the client sends: a client
     * that chose its own key would simply choose a clean one.
     */
    public function issue(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->pow->create('pow:'.$request->ip(), $request->ip())->toArray(),
        );
    }
}
