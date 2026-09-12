<?php

namespace Tests\Fakes;

use App\Domain\Providers\Cloudflare\CloudflareApi;
use RuntimeException;
use Throwable;

/**
 * Scriptable in-memory CloudflareApi. Tests queue response envelopes
 * (or exceptions) per path — a response may be a callable receiving
 * the query parameters, which is how multi-page collections are
 * scripted — and can inspect every call afterwards. Because it
 * implements CloudflareApi, only GET is expressible: the same
 * read-only guarantee the real client carries.
 */
final class FakeCloudflareApi implements CloudflareApi
{
    /** @var array<string, mixed> envelopes or callables(array): array */
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

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = $path;

        $exception = $this->exceptions[$path][0] ?? null;

        if ($exception !== null) {
            $this->exceptions[$path] = array_slice($this->exceptions[$path], 1);

            throw $exception;
        }

        if (! array_key_exists($path, $this->responses)) {
            throw new RuntimeException("FakeCloudflareApi has no scripted response for [{$path}].");
        }

        $response = $this->responses[$path];

        return is_callable($response) ? $response($query) : $response;
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
