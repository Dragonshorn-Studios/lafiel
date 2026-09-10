<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class VerifyDatabaseHealth
{
    /**
     * Fail the health endpoint when the database is unreachable.
     */
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');
    }
}
