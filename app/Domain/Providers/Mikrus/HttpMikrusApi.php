<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The Laravel HTTP client behind the read-only MikrusApi interface.
 * Every mikr.us endpoint is a POST carrying the account API key both
 * as the `key` form field and in the Authorization header — the docs
 * sanction either, and a live Connect answered 400 to the form field
 * alone, so both ride along. `srv` rides only on per-server reads.
 * HTTP failures become the typed provider exceptions with sanitized
 * messages. On the account-level `/serwery` calls (credential
 * validation and inventory discovery) 400, 401, and 403 mean the key
 * was rejected and are never retried — a live Connect answered a bad
 * key with 400 plus an explanatory body; on per-server `/info` calls
 * only 401/403 reject, because 400 is a generic client-error status
 * there (a stale `srv`, most plausibly) and one odd server must
 * degrade the batch rather than reject healthy credentials. 429, 5xx,
 * and connection failures are transient everywhere. mikr.us documents
 * no error format, so failure messages other than rate limits may
 * append a short excerpt of the API's own error body — control bytes
 * stripped, whitespace-collapsed, length-capped, and with the API key
 * scrubbed — never other payload or credential material.
 */
final class HttpMikrusApi implements MikrusApi
{
    private const API_BASE_URL = 'https://api.mikr.us';

    private const EXCERPT_LIMIT = 120;

    public function __construct(private readonly string $apiKey) {}

    public function post(string $path, array $fields = []): array
    {
        $response = $this->send($path, $fields);

        $status = $response->status();
        $excerpt = $this->errorExcerpt($response);

        // A 400 is key-specific only where the key is the only variable
        // in play: the account listing. Anywhere else the request
        // carries per-server parameters, so a 400 is treated like any
        // other generic failure — transient, degrading, not rejecting.
        if (in_array($status, [401, 403], true) || ($status === 400 && $path === '/serwery')) {
            throw new InvalidCredentialsException(
                "Mikr.us rejected the API key for [{$path}] (HTTP {$status})".($excerpt === '' ? '.' : $excerpt)
            );
        }

        if ($status === 429) {
            throw new TransientProviderException("Mikr.us rate limit reached for [{$path}] (HTTP 429).");
        }

        if ($response->failed()) {
            throw new TransientProviderException(
                "Mikr.us API request failed for [{$path}] (HTTP {$status})".($excerpt === '' ? '.' : $excerpt)
            );
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
                ->withHeaders(['Authorization' => $this->apiKey])
                ->connectTimeout(5)
                ->timeout(15)
                ->post($path, ['key' => $this->apiKey, ...$fields]);
        } catch (ConnectionException) {
            throw new TransientProviderException("Mikr.us API could not be reached for [{$path}].");
        }
    }

    /**
     * A sanitized "excerpt" suffix for failure messages, or an empty
     * string when the body carries nothing readable. The API key is
     * scrubbed first (a provider that echoes the request would
     * otherwise leak it), control bytes are dropped and whitespace
     * collapses (an excerpt rides into stored summaries and log
     * context, so ANSI escapes must not survive), and the length is
     * capped so a hostile or HTML-heavy body cannot flood the message.
     */
    private function errorExcerpt(Response $response): string
    {
        $body = trim($response->body());

        if ($body === '') {
            return '';
        }

        $body = str_replace($this->apiKey, '[redacted]', $body);
        $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
        $body = preg_replace('/[[:cntrl:]]/u', '', $body) ?? $body;

        return ': '.Str::limit($body, self::EXCERPT_LIMIT);
    }
}
