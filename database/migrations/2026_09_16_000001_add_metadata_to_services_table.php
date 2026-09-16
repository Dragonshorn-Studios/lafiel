<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Provider-specific lifecycle details (offer, creation,
            // expiration) kept next to the canonical record; never
            // queried structurally, always through the provider domain.
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
