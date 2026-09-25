<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            // Progress marker for the sync activity view: the phase a
            // run reached, kept on terminal runs so a failure says where
            // it died. Null for queued runs and pre-migration rows.
            $table->string('stage')->nullable()->after('status');
        });

        // Mirrors the SyncStage enum; a new case needs its own migration
        // to widen this list. The constraint is unnamed: it cannot be
        // dropped by name on sqlite, and dropping the column below
        // removes it on both engines anyway.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE sync_runs ADD CHECK (stage IS NULL OR stage IN ('credentials', 'inventory', 'costs', 'persisting'))"
            );
        } else {
            DB::statement("
                CREATE TRIGGER check_sync_runs_stage_insert BEFORE INSERT ON sync_runs
                WHEN NEW.stage IS NOT NULL AND NEW.stage NOT IN ('credentials', 'inventory', 'costs', 'persisting')
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
            DB::statement("
                CREATE TRIGGER check_sync_runs_stage_update BEFORE UPDATE ON sync_runs
                WHEN NEW.stage IS NOT NULL AND NEW.stage NOT IN ('credentials', 'inventory', 'costs', 'persisting')
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
