<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lafiel has exactly one local administrator. A unique index over a constant
     * expression makes "at most one users row" a database-owned invariant, so a
     * setup race cannot produce a second account even if application locks fail.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX users_single_administrator ON users ((1))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_single_administrator');
    }
};
