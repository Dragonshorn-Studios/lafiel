<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $this->addEnumChecks();
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

    private function addEnumChecks(): void
    {
        $checks = [
            "source IN ('builtin', 'manual', 'catalog')",
        ];

        if (DB::getDriverName() !== 'sqlite') {
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE subscription_presets ADD CHECK ({$check})");
            }
        } else {
            $whenClause = "NEW.source NOT IN ('builtin', 'manual', 'catalog')";

            DB::statement("
                CREATE TRIGGER check_subscription_presets_source_insert BEFORE INSERT ON subscription_presets
                WHEN {$whenClause}
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
            DB::statement("
                CREATE TRIGGER check_subscription_presets_source_update BEFORE UPDATE ON subscription_presets
                WHEN {$whenClause}
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
        }
    }
};
