<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Validation\Rules;

use Closure;
use GtsMeghni\LaravelCaptcha\Pow\Pow;
use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;

/**
 * Validates a proof-of-work nonce against the token issued with it.
 *
 * Attach to the nonce field; the token is read from the field named in
 * `captcha.pow.fields.token` unless another is given.
 */
class ProofOfWork implements ValidationRule, ValidatorAwareRule
{
    protected Validator $validator;

    public function __construct(protected ?string $tokenField = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pow = app(Pow::class);

        if (! $pow->enabled()) {
            return;
        }

        $token = data_get($this->validator->getData(), $this->tokenField());

        if (! is_string($value) || ! is_string($token)) {
            $fail('captcha::messages.pow_missing')->translate();

            return;
        }

        if (! $pow->verify($value, $token, request()->ip())) {
            $fail('captcha::messages.pow_invalid')->translate();
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
            ?? Value::string(config('captcha.pow.fields.token'), 'pow_token');
    }
}
