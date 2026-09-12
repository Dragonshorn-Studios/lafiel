<?php

namespace Tests\Fakes;

use App\Domain\Providers\Ovh\OvhApi;
use RuntimeException;
use Throwable;

/**
 * Scriptable in-memory OvhApi. Tests queue responses (or exceptions)
 * per path and can inspect every call afterwards. Because it
 * implements OvhApi, only GET is expressible — the same read-only
 * guarantee the real client carries.
 */
final class FakeOvhApi implements OvhApi
{
    /** @var array<string, mixed> */
    public array $responses = [];

    /** @var array<string, list<Throwable>> exceptions queued per path, one consumed per call */
    public array $exceptions = [];

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function get(string $path, array $parameters = []): mixed
    {
        $this->calls[] = $path;

        $exception = $this->exceptions[$path][0] ?? null;

        if ($exception !== null) {
            $this->exceptions[$path] = array_slice($this->exceptions[$path], 1);

            throw $exception;
        }

        if (! array_key_exists($path, $this->responses)) {
            throw new RuntimeException("FakeOvhApi has no scripted response for [{$path}].");
        }

        return $this->responses[$path];
    }

    /**
     * @param  list<Throwable>  $exceptions
     */
    public function throwOn(string $path, array $exceptions): self
    {
        $this->exceptions[$path] = $exceptions;

        return $this;
    }

    public function callCount(string $path): int
    {
        return count(array_filter($this->calls, fn (string $call): bool => $call === $path));
    }
}
