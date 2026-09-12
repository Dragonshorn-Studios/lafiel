<?php

namespace App\Domain\Providers\Cloudflare;

/**
 * The read-only Cloudflare client. The interface can only express GET,
 * so no caller — adapter, connection test, or spike — can reach a
 * mutating Cloudflare endpoint.
 */
interface CloudflareApi
{
    /**
     * One API v4 call. Returns the decoded response envelope
     * (`success`, `result`, `result_info`) so callers keep the paging
     * metadata next to the page payload. Failures become the typed
     * provider exceptions with sanitized messages.
     *
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array;
}
