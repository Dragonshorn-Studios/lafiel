<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->string('status')->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Per-phase counts and sanitized warnings only; no raw payloads.
            $table->json('counts')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE sync_runs ADD CHECK (status IN ('queued', 'running', 'succeeded', 'partial', 'failed', 'cancelled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
