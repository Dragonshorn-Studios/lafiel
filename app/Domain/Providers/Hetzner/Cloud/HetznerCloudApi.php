<?php

namespace App\Domain\Providers\Hetzner\Cloud;

/**
 * The read-only Hetzner Cloud client. The interface can only express
 * GET, so no caller — adapter, connection test, or spike — can reach a
 * mutating Hetzner endpoint.
 */
interface HetznerCloudApi
{
    /**
     * One API call. Returns the decoded response body (the resource
     * collection plus Hetzner's `meta` pagination block). Failures
     * become the typed provider exceptions with sanitized messages.
     *
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;
}
