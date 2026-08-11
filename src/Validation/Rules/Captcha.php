<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Validation\Rules;

use Closure;
use GtsMeghni\LaravelCaptcha\Facades\Captcha as CaptchaFacade;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;

/**
 * Validates the answer against the token found elsewhere in the request.
 *
 * The rule is attached to the answer field, and reads the token from the field
 * named in `captcha.fields.token` unless another is given.
 */
class Captcha implements ValidationRule, ValidatorAwareRule
{
    protected Validator $validator;

    public function __construct(protected ?string $tokenField = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CaptchaFacade::enabled()) {
            return;
        }

        $token = data_get($this->validator->getData(), $this->tokenField());

        if (! is_string($value) || ! is_string($token)) {
            $fail('captcha::messages.missing')->translate();

            return;
        }

        if (! CaptchaFacade::verify($value, $token, request()->ip())) {
            $fail('captcha::messages.invalid')->translate();
        }
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    protected function tokenField(): string
    {
        return $this->tokenField
            ?? Value::string(config('captcha.fields.token'), 'captcha_token');
    }
}
