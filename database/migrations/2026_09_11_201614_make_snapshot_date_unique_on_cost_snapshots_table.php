<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cost_snapshots', function (Blueprint $table) {
            // One snapshot per date: a later capture on the same date
            // updates the row instead of adding noise.
            $table->dropIndex(['snapshot_date']);
            $table->unique('snapshot_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_snapshots', function (Blueprint $table) {
            $table->dropUnique(['snapshot_date']);
            $table->index('snapshot_date');
        });
    }
};
