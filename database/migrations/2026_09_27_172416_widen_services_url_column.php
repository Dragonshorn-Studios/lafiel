<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen services.url to match what the manual-cost validation
     * already allows (max:2048) — preset URLs with tracking params
     * were the first realistic producer of links beyond varchar(255).
     *
     * Raw ALTERs on purpose: sqlite ignores VARCHAR lengths anyway,
     * and Schema change() would rebuild the table there and drop the
     * CHECK triggers the services invariants rely on.
     */
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE services ALTER COLUMN url TYPE VARCHAR(2048)'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE services MODIFY url VARCHAR(2048) NULL'),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE services ALTER COLUMN url TYPE VARCHAR(255)'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE services MODIFY url VARCHAR(255) NULL'),
            default => null,
        };
    }
};
