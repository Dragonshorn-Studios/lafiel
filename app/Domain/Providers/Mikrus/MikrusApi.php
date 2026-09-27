<?php

namespace App\Domain\Providers\Mikrus;

/**
 * The read-only mikr.us client. The mikr.us API is POST-form based for
 * every endpoint, so the interface expresses only `post` with field
 * payloads. Read-only-ness is a caller contract: the only intended
 * callers are the adapter's inventory reads (`/serwery`, `/info`) —
 * mikr.us exposes no billing surface, and no mutating endpoint is ever
 * wired up here.
 */
interface MikrusApi
{
    /**
     * One API call. Returns the decoded response body — a list for
     * collection endpoints (`/serwery`), a map for object endpoints
     * (`/info`). Failures become the typed provider exceptions with
     * sanitized messages.
     *
     * @param  array<string, int|string>  $fields
     * @return array<mixed>
     */
    public function post(string $path, array $fields = []): array;
}
