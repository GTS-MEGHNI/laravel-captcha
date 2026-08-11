<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Http\Controllers;

use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two endpoints a client needs.
 *
 * Responses are deliberately unwrapped. An application with its own envelope
 * should disable `captcha.routes.enabled` and call the facade from its own
 * controller instead of fighting this one.
 */
class CaptchaController
{
    public function __construct(protected Captcha $captcha) {}

    /**
     * Issue a challenge.
     *
     * The preset name arrives from the client, so an unknown one is a bad
     * request rather than a server error.
     */
    public function issue(Request $request): JsonResponse
    {
        $query = $request->query('preset', 'default');
        $preset = is_string($query) && $query !== '' ? $query : 'default';

        try {
            return new JsonResponse($this->captcha->create($preset, $request->ip())->toArray());
        } catch (CaptchaException) {
            return new JsonResponse([
                'message' => "Unknown captcha preset [$preset].",
            ], 422);
        }
    }

    /**
     * Stream the image for an issued token.
     *
     * Answered and expired tokens are indistinguishable here, both 404.
     */
    public function image(string $token): Response
    {
        $png = $this->captcha->image($token);

        if ($png === null) {
            return new Response('', 404);
        }

        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
