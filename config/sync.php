<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-account lock
    |--------------------------------------------------------------------------
    |
    | One sync per provider account. The atomic lock guards the whole run —
    | credential validation, fetches, and persistence — and is not
    | refreshed mid-run, so this TTL must exceed the slowest legitimate
    | sync. A queued or running sync run older than stale_run_after_seconds
    | (which must exceed the lock TTL) is treated as abandoned and
    | reconciled by the next attempt.
    |
    */

    'lock_ttl_seconds' => 600,

    // Runs older than this are abandoned even if their job never returned:
    // generous, because a live run whose lock lapsed is protected by the
    // claim-guarded finish, not by this threshold.
    'stale_run_after_seconds' => 1800,

    /*
    |--------------------------------------------------------------------------
    | Transient retry budget
    |--------------------------------------------------------------------------
    |
    | HTTP 429, 5xx, and timeout failures retry with bounded exponential
    | backoff and jitter: attempt N sleeps a random half-to-full share of
    | min(max_delay, base * 2^(N-1)). Invalid credentials are never
    | retried.
    |
    */

    'retry' => [
        'max_attempts' => 4,
        'base_delay_ms' => 500,
        'max_delay_ms' => 15000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service lifecycle
    |--------------------------------------------------------------------------
    |
    | A provider-discovered service becomes inactive only after this many
    | successful complete inventories have omitted it. Partial or failed
    | inventory never advances the counter: absence is not cancellation.
    |
    */

    'inactive_after_complete_runs' => env('LAFIEL_INACTIVE_AFTER_COMPLETE_RUNS', 3),

    /*
    |--------------------------------------------------------------------------
    | Scheduled sync
    |--------------------------------------------------------------------------
    |
    | Every enabled account syncs once a day at this local time. The
    | console schedule and the sync activity view both read it, so the
    | page can say when the next scheduled run is due.
    |
    */

    'scheduled_at' => '04:00',

];
