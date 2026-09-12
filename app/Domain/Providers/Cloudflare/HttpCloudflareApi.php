<?php

namespace App\Domain\Providers\Cloudflare;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Laravel HTTP client behind the read-only CloudflareApi
 * interface. HTTP failures become the typed provider exceptions with
 * sanitized messages: 401/403 mean the credentials were rejected and
 * are never retried; 429, 5xx, and connection failures are transient.
 * Messages carry only the request path and status code — never
 * credential material or response bodies.
 */
final class HttpCloudflareApi implements CloudflareApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

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
            throw new TransientProviderException("Cloudflare API could not be reached for [{$path}].");
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw new InvalidCredentialsException("Cloudflare rejected the credentials for [{$path}] (HTTP {$status}).");
        }

        if ($status === 429) {
            throw new TransientProviderException("Cloudflare rate limit reached for [{$path}] (HTTP 429).");
        }

        if ($response->failed()) {
            throw new TransientProviderException("Cloudflare API request failed for [{$path}] (HTTP {$status}).");
        }

        $envelope = $response->json();

        // API v4 can answer within 2xx with success:false — an
        // API-level refusal that is neither credentials nor an outage,
        // so it fails the phase without inviting a retry.
        if (! is_array($envelope) || ($envelope['success'] ?? false) !== true) {
            throw new ProviderException("Cloudflare API refused the request for [{$path}].");
        }

        return $envelope;
    }
}
