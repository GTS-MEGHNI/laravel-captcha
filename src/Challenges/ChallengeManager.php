<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Challenges;

use GtsMeghni\LaravelCaptcha\Exceptions\CaptchaException;
use GtsMeghni\LaravelCaptcha\Support\Preset;

/**
 * Resolves a preset's `type` to the generator that builds it.
 */
class ChallengeManager
{
    /** @var array<string, ChallengeGenerator|callable(): ChallengeGenerator> */
    protected array $generators = [];

    public function __construct()
    {
        $this->extend('text', static fn (): ChallengeGenerator => new TextChallenge);
    }

    /**
     * Register a generator, replacing any type of the same name.
     *
     * @param  ChallengeGenerator|callable(): ChallengeGenerator  $generator
     */
    public function extend(string $type, ChallengeGenerator|callable $generator): self
    {
        $this->generators[$type] = $generator;

        return $this;
    }

    public function generate(Preset $preset): Challenge
    {
        return $this->generator($preset->type)->generate($preset);
    }

    public function generator(string $type): ChallengeGenerator
    {
        if (! isset($this->generators[$type])) {
            throw CaptchaException::unknownChallengeType($type);
        }

        $generator = $this->generators[$type];

        if (! $generator instanceof ChallengeGenerator) {
            $generator = $generator();
            $this->generators[$type] = $generator;
        }

        return $generator;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->generators);
    }
}
