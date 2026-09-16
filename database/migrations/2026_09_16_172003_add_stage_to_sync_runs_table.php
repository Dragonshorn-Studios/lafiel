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

        DB::statement(
            "ALTER TABLE sync_runs ADD CHECK (stage IS NULL OR stage IN ('credentials', 'inventory', 'costs', 'persisting'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE sync_runs DROP CONSTRAINT sync_runs_stage_check');

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
