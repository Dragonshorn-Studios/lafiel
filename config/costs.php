<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display currency
    |--------------------------------------------------------------------------
    |
    | v1 displays PLN. Totals are always computed per currency; there is
    | no silent FX source. Converting another currency into the display
    | currency requires an explicitly stored manual rate.
    |
    */

    'display_currency' => 'PLN',

    /*
    |--------------------------------------------------------------------------
    | Evidence freshness
    |--------------------------------------------------------------------------
    |
    | Synced cost evidence older than this many days (relative to the
    | projection date) counts as stale. Manual evidence never goes stale:
    | it has no provider observation behind it.
    |
    */

    'freshness_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Calculation version
    |--------------------------------------------------------------------------
    |
    | Bumped whenever the projection algorithm changes meaningfully, so
    | stored snapshots remain replayable and comparable.
    |
    */

    'calculation_version' => 'v1',

];
