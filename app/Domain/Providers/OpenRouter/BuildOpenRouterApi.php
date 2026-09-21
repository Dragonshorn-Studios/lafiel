<?php

namespace App\Domain\Providers\OpenRouter;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;

class BuildOpenRouterApi
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function build(array $credentials): OpenRouterApi
    {
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if (trim($apiKey) === '') {
            throw new InvalidCredentialsException('No OpenRouter API key was provided.');
        }

        return new HttpOpenRouterApi($apiKey);
    }
}
