<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->index();
            // Captured output: totals and completeness counters at capture time.
            $table->json('totals');
            $table->json('completeness');
            $table->string('calculation_version');
            // The FX values used by this snapshot; today's rate never rewrites it.
            $table->json('fx_used')->nullable();
            $table->json('breakdown');
            $table->string('input_checksum')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_snapshots');
    }
};
