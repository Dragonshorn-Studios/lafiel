<?php

namespace App\Domain\Providers\OpenRouter;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;

interface OpenRouterApi
{
    /**
     * Perform a GET request against the OpenRouter API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws InvalidCredentialsException when API key is rejected
     * @throws TransientProviderException on rate limit, timeout, or server error
     * @throws ProviderException on client errors
     */
    public function get(string $endpoint, array $query = []): array;
}
