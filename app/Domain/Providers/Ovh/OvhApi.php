<?php

namespace App\Domain\Providers\Ovh;

/**
 * Authenticated read access to one OVH account's API. The interface can
 * only express GET, so no caller — adapter, spike, or connection test —
 * can reach an OVH mutating endpoint. Implementations translate HTTP
 * failures into the typed provider exceptions: the credentials being
 * rejected is permanent, 429/5xx/timeout is transient.
 */
interface OvhApi
{
    /**
     * @param  array<string, int|string>  $parameters  query parameters
     * @return mixed the decoded JSON response body
     */
    public function get(string $path, array $parameters = []): mixed;
}
