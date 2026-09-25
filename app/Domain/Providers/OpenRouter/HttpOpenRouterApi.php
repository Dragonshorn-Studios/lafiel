<?php

namespace App\Domain\Providers\OpenRouter;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpOpenRouterApi implements OpenRouterApi
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    public function __construct(private readonly string $apiKey) {}

    public function get(string $endpoint, array $query = []): array
    {
        $url = self::BASE_URL.'/'.ltrim($endpoint, '/');

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(10)
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new TransientProviderException("OpenRouter connection failed: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new InvalidCredentialsException('OpenRouter API key was rejected or unauthorized.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new TransientProviderException("OpenRouter API returned HTTP {$response->status()}.");
        }

        if ($response->failed()) {
            throw new ProviderException("OpenRouter API error (HTTP {$response->status()}).");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
