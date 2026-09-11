<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One sync per provider account: the database owns the invariant
     * that a queued or running run is unique per account, the same way
     * it owns service identity. RequestSync inserts and treats a
     * uniqueness violation as "already syncing"; the index closes the
     * dispatch race.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX sync_runs_one_active_per_account '
            .'ON sync_runs (provider_account_id) '
            .'WHERE status IN (\'queued\', \'running\')'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sync_runs_one_active_per_account');
    }
};
