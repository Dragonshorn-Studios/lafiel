<?php

namespace App\Domain\Providers\Hetzner\Cloud;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Laravel HTTP client behind the read-only HetznerCloudApi
 * interface. HTTP failures become the typed provider exceptions with
 * sanitized messages: 401/403 mean the token was rejected and are
 * never retried; 429 (Hetzner also sends Retry-After), 5xx, rate
 * limits, and connection failures are transient. Messages carry only
 * the request path and status code — never credential material or
 * response bodies.
 */
final class HttpHetznerCloudApi implements HetznerCloudApi
{
    private const BASE_URL = 'https://api.hetzner.cloud/v1';

    public function __construct(private readonly string $apiToken) {}

    public function get(string $path, array $query = []): array
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withToken($this->apiToken)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new TransientProviderException("Hetzner Cloud API could not be reached for [{$path}].");
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw new InvalidCredentialsException("Hetzner Cloud rejected the credentials for [{$path}] (HTTP {$status}).");
        }

        if ($status === 429) {
            throw new TransientProviderException("Hetzner Cloud rate limit reached for [{$path}] (HTTP 429).");
        }

        if ($response->failed()) {
            throw new TransientProviderException("Hetzner Cloud API request failed for [{$path}] (HTTP {$status}).");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new TransientProviderException("Hetzner Cloud API returned an unreadable body for [{$path}].");
        }

        return $body;
    }
}
