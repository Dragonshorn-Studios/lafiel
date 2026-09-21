<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            // Null for manual services that no provider account discovered.
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            // Raw provider vocabulary, kept apart from the canonical category.
            $table->string('provider_type')->nullable();
            $table->string('category')->index();
            $table->string('name');
            $table->string('url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('missing_complete_runs')->default(0);
            $table->string('lifecycle_state')->default('active');
            $table->timestamps();
        });

        // Provider identity is a database-owned invariant. Manual services
        // have no external_id and stay outside the constraint.
        DB::statement(
            'CREATE UNIQUE INDEX services_provider_identity_unique '
            .'ON services (provider_account_id, external_id) '
            .'WHERE external_id IS NOT NULL'
        );

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE TRIGGER check_services_lifecycle BEFORE INSERT ON services WHEN NEW.lifecycle_state NOT IN ('active', 'missing', 'inactive') BEGIN SELECT RAISE(FAIL, 'CHECK constraint failed'); END;"
            );
        } else {
            DB::statement(
                "ALTER TABLE services ADD CHECK (lifecycle_state IN ('active', 'missing', 'inactive'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
