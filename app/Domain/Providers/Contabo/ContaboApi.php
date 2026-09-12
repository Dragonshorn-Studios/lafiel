<?php

namespace App\Domain\Providers\Contabo;

/**
 * The read-only Contabo client. The interface can only express GET, so
 * no caller — adapter, connection test, or spike — can reach a
 * mutating Contabo endpoint. The OAuth2 token exchange is auth
 * plumbing inside the implementation, not a resource mutation.
 */
interface ContaboApi
{
    /**
     * One API call. Returns the decoded response body: the `data`
     * collection plus Contabo's `_metadata` (total counts) so callers
     * can walk the pagination. Failures become the typed provider
     * exceptions with sanitized messages.
     *
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;
}
