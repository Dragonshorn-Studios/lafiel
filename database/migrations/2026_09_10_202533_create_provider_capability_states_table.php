<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_capability_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('capability_key')->index();
            $table->boolean('supported')->default(false);
            $table->boolean('healthy')->default(false);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_account_id', 'capability_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_capability_states');
    }
};
