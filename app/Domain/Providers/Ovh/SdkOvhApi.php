<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Ovh\Api;

/**
 * The official OVH SDK behind the read-only OvhApi interface. HTTP
 * failures become the two typed provider exceptions with sanitized
 * messages: 401/403 mean the credentials were rejected and are never
 * retried; 429, other 4xx, 5xx, and connection failures are transient.
 * Messages carry only the request path and status code — never
 * credential material or response bodies.
 */
final class SdkOvhApi implements OvhApi
{
    public function __construct(private readonly Api $api) {}

    public function get(string $path, array $parameters = []): mixed
    {
        try {
            return $this->api->get($path, $parameters === [] ? null : $parameters);
        } catch (ClientException $exception) {
            throw $this->clientException($path, $exception);
        } catch (ConnectException) {
            throw new TransientProviderException("OVH API could not be reached for [{$path}].");
        } catch (ServerException $exception) {
            throw new TransientProviderException("OVH API server error for [{$path}] (HTTP {$exception->getResponse()?->getStatusCode()}).");
        } catch (GuzzleException) {
            throw new TransientProviderException("OVH API request failed for [{$path}].");
        }
    }

    private function clientException(string $path, RequestException $exception): InvalidCredentialsException|TransientProviderException
    {
        $status = $exception->getResponse()?->getStatusCode() ?? 0;

        if (in_array($status, [401, 403], true)) {
            return new InvalidCredentialsException("OVH rejected the credentials for [{$path}] (HTTP {$status}).");
        }

        if ($status === 429) {
            return new TransientProviderException("OVH rate limit reached for [{$path}] (HTTP 429).");
        }

        return new TransientProviderException("OVH API request failed for [{$path}] (HTTP {$status}).");
    }

    /**
     * Build the SDK client for one credential payload. Both endpoints are
     * already validated by the schema; anything the SDK itself rejects is
     * a malformed stored payload, which only a human can fix.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function forPayload(array $payload): self
    {
        $api = new Api(
            (string) $payload['application_key'],
            (string) $payload['application_secret'],
            (string) $payload['endpoint'],
            (string) $payload['consumer_key'],
            new Client([
                'timeout' => 30,
                'connect_timeout' => 5,
            ]),
        );

        return new self($api);
    }
}
