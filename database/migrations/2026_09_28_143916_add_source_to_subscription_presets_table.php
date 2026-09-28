<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tag every preset with its origin. Existing rows default to
     * 'builtin' — correct for the seeded built-ins; the only other
     * rows that can exist at this point were hand-added on the Plans
     * page within a day of the library shipping, and they will read
     * as built-in (hidden once a catalog is imported, deletable)
     * until re-added.
     */
    public function up(): void
    {
        Schema::table('subscription_presets', function (Blueprint $table) {
            $table->string('source')->default('builtin')->after('url');
            $table->string('source_url', 2048)->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_presets', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_url']);
        });
    }
};
