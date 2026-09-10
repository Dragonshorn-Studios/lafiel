<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            // Encrypted at rest with APP_KEY; never exposed through serialization.
            $table->text('payload');
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('fingerprint')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
