<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Http\Middleware;

use Closure;
use GtsMeghni\LaravelCaptcha\Captcha;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request whose captcha answer is missing or wrong.
 *
 * Failures surface as a ValidationException, so they render as a 422 with the
 * usual error shape rather than needing a bespoke exception and handler.
 */
class ValidateCaptcha
{
    public function __construct(
        protected Captcha $captcha,
        protected Config $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->captcha->enabled()) {
            return $next($request);
        }

        $answerField = Value::string($this->config->get('captcha.fields.answer'), 'captcha');
        $tokenField = Value::string($this->config->get('captcha.fields.token'), 'captcha_token');

        $answer = $request->input($answerField);
        $token = $request->input($tokenField);

        if (! is_string($answer) || ! is_string($token) || $answer === '' || $token === '') {
            $this->fail($answerField, 'captcha::messages.missing');
        }

        if (! $this->captcha->verify($answer, $token, $request->ip())) {
            $this->fail($answerField, 'captcha::messages.invalid');
        }

        return $next($request);
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [trans($message)],
        ]);
    }
}
