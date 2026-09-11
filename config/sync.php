<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-account lock
    |--------------------------------------------------------------------------
    |
    | One sync per provider account. The atomic lock guards the fetch and
    | persist section; a queued or running sync run older than this TTL is
    | treated as abandoned and reconciled by the next attempt.
    |
    */

    'lock_ttl_seconds' => 600,

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

];
