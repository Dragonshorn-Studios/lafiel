<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER check_cost_items_manual_override BEFORE INSERT ON cost_items WHEN NEW.is_manual_override AND NEW.source_kind != 'manual' BEGIN SELECT RAISE(FAIL, 'CHECK constraint failed'); END;");
            DB::statement("CREATE TRIGGER check_cost_items_known_amount BEFORE INSERT ON cost_items WHEN NEW.amount_state != 'unknown' AND (NEW.amount_minor IS NULL OR NEW.currency IS NULL) BEGIN SELECT RAISE(FAIL, 'CHECK constraint failed'); END;");
        } else {
            // A conscious manual override is only meaningful on manual
            // evidence; a known amount always carries its minor units and
            // currency. Both invariants are database-owned like the enum
            // checks.
            DB::statement(
                'ALTER TABLE cost_items ADD CHECK (NOT is_manual_override OR source_kind = \'manual\')'
            );

            DB::statement(
                'ALTER TABLE cost_items ADD CHECK (amount_state = \'unknown\' OR (amount_minor IS NOT NULL AND currency IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        // CHECK constraints are unnamed in SQLite and auto-named in
        // Postgres; rebuilding the table is the only portable way back.
        throw new RuntimeException('This migration is irreversible without rebuilding cost_items.');
    }
};
