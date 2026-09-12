<?php

namespace App\Domain\Ops;

/**
 * One row of the pipeline check matrix. Build through the named
 * factories: `critical` marks checks whose failure means the pipeline
 * is broken (they fail /up and the lafiel:ops exit code), while
 * non-critical rows surface degradation without turning the app red.
 */
final readonly class OpsCheck
{
    private function __construct(
        public string $name,
        public bool $ok,
        public bool $critical,
        public string $detail,
    ) {}

    public static function pass(string $name, string $detail, bool $critical = true): self
    {
        return new self($name, true, $critical, $detail);
    }

    public static function fail(string $name, string $detail, bool $critical = true): self
    {
        return new self($name, false, $critical, $detail);
    }

    /**
     * The failure rule every consumer (/up, lafiel:ops) shares: a
     * critical check that is not ok breaks the pipeline.
     */
    public function isFailure(): bool
    {
        return $this->critical && ! $this->ok;
    }
}
