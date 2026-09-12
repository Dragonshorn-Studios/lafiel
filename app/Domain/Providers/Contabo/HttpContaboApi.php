<?php

namespace App\Domain\Providers\Contabo;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Laravel HTTP client behind the read-only ContaboApi interface.
 * The OAuth2 client-credentials exchange happens here — auth plumbing
 * that never touches a resource endpoint. HTTP failures become the
 * typed provider exceptions with sanitized messages: 401/403 mean the
 * credentials were rejected and are never retried; 429, 5xx, and
 * connection failures are transient. Messages carry only the request
 * path and status code — never credential material or response bodies.
 */
final class HttpContaboApi implements ContaboApi
{
    private const API_BASE_URL = 'https://api.contabo.com';

    private const TOKEN_URL = 'https://auth.contabo.com/token';

    /** Cached OAuth2 access token; null until first exchanged. */
    private ?string $token = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->send($path, $query, $this->token());

        // One mid-run 401 means the short-lived token expired under us;
        // force one fresh exchange and retry once. A second 401 falls
        // through to the rejection mapping below.
        if ($response->status() === 401) {
            $this->token = null;
            $response = $this->send($path, $query, $this->token());
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw new InvalidCredentialsException("Contabo rejected the credentials for [{$path}] (HTTP {$status}).");
        }

        if ($status === 429) {
            throw new TransientProviderException("Contabo rate limit reached for [{$path}] (HTTP 429).");
        }

        if ($response->failed()) {
            throw new TransientProviderException("Contabo API request failed for [{$path}] (HTTP {$status}).");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new TransientProviderException("Contabo API returned an unreadable body for [{$path}].");
        }

        return $body;
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function send(string $path, array $query, string $token): Response
    {
        try {
            return Http::baseUrl(self::API_BASE_URL)
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new TransientProviderException("Contabo API could not be reached for [{$path}].");
        }
    }

    /**
     * The cached OAuth2 access token, exchanged on first use. A
     * rejected exchange is a permanent, human-fixable credential
     * problem; a transport failure is transient.
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(5)
                ->timeout(30)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
        } catch (ConnectionException) {
            throw new TransientProviderException('Contabo authentication could not be reached.');
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new InvalidCredentialsException('Contabo rejected the credentials (HTTP '.$response->status().').');
        }

        if ($response->failed()) {
            throw new TransientProviderException('Contabo authentication failed (HTTP '.$response->status().').');
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new TransientProviderException('Contabo authentication returned no access token.');
        }

        return $this->token = $accessToken;
    }
}
