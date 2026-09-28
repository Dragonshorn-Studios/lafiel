<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Laravel HTTP client behind the read-only MikrusApi interface.
 * Every mikr.us endpoint is a POST carrying the account API key as a
 * form field (plus `srv` for per-server reads). HTTP failures become
 * the typed provider exceptions with sanitized messages: 401/403 mean
 * the key was rejected and are never retried; 429, 5xx, and connection
 * failures are transient. Messages carry only the request path and
 * status code — never credential material or response bodies.
 */
final class HttpMikrusApi implements MikrusApi
{
    private const API_BASE_URL = 'https://api.mikr.us';

    public function __construct(private readonly string $apiKey) {}

    public function post(string $path, array $fields = []): array
    {
        $response = $this->send($path, $fields);

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw new InvalidCredentialsException("Mikr.us rejected the API key for [{$path}] (HTTP {$status}).");
        }

        if ($status === 429) {
            throw new TransientProviderException("Mikr.us rate limit reached for [{$path}] (HTTP 429).");
        }

        if ($response->failed()) {
            throw new TransientProviderException("Mikr.us API request failed for [{$path}] (HTTP {$status}).");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new TransientProviderException("Mikr.us API returned an unreadable body for [{$path}].");
        }

        /** @var array<mixed> $body */
        return $body;
    }

    /**
     * @param  array<string, int|string>  $fields
     */
    private function send(string $path, array $fields): Response
    {
        try {
            return Http::baseUrl(self::API_BASE_URL)
                ->asForm()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($path, ['key' => $this->apiKey, ...$fields]);
        } catch (ConnectionException) {
            throw new TransientProviderException("Mikr.us API could not be reached for [{$path}].");
        }
    }
}
