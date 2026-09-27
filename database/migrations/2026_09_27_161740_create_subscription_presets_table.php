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
        Schema::create('subscription_presets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('vendor');
            $table->string('name');
            $table->string('category');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('period');
            $table->boolean('auto_renew')->default(true);
            $table->string('url', 2048)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        $this->addEnumChecks();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_presets');
    }

    private function addEnumChecks(): void
    {
        $checks = [
            "period IN ('monthly', 'quarterly', 'annual', 'one_time', 'unknown')",
            'amount_minor >= 0',
        ];

        if (DB::getDriverName() !== 'sqlite') {
            foreach ($checks as $check) {
                DB::statement("ALTER TABLE subscription_presets ADD CHECK ({$check})");
            }
        } else {
            $conditions = [
                "NEW.period NOT IN ('monthly', 'quarterly', 'annual', 'one_time', 'unknown')",
                'NEW.amount_minor < 0',
            ];
            $whenClause = implode(' OR ', $conditions);

            DB::statement("
                CREATE TRIGGER check_subscription_presets_enums_insert BEFORE INSERT ON subscription_presets
                WHEN {$whenClause}
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
            DB::statement("
                CREATE TRIGGER check_subscription_presets_enums_update BEFORE UPDATE ON subscription_presets
                WHEN {$whenClause}
                BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed'); END;
            ");
        }
    }
};
