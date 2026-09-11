<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_items', function (Blueprint $table) {
            $table->id();
            // Stable identity of one source record and period. Display names
            // never participate in identity.
            $table->string('identity_key')->unique();
            // Groups competing evidence for the same charge; not unique.
            $table->string('logical_charge_key')->index();
            $table->string('source_kind');
            $table->string('charge_kind');
            // Billing period of the charge; drives the exact monthly and
            // annual equivalents.
            $table->string('period')->default('monthly');
            // Integer minor units plus ISO-4217 currency. Never floats.
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('amount_state');
            $table->string('evidence_state');
            $table->string('tax_basis')->default('unknown');
            $table->string('allocation_state')->default('direct');
            // A manual override wins over stronger evidence only when
            // consciously enabled.
            $table->boolean('is_manual_override')->default(false);
            // Validity window; superseded facts are closed, never deleted.
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('source_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->addEnumChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_items');
    }

    private function addEnumChecks(): void
    {
        $checks = [
            "source_kind IN ('subscription', 'renewal_quote', 'usage', 'invoice', 'manual')",
            "charge_kind IN ('recurring_fixed', 'usage', 'one_time')",
            "period IN ('monthly', 'quarterly', 'annual', 'one_time', 'unknown')",
            "amount_state IN ('known', 'unknown')",
            "evidence_state IN ('actual', 'estimate', 'quote', 'manual')",
            "tax_basis IN ('inclusive', 'exclusive', 'unknown')",
            "allocation_state IN ('direct', 'shared_unallocated', 'allocated')",
        ];

        foreach ($checks as $check) {
            DB::statement("ALTER TABLE cost_items ADD CHECK ({$check})");
        }
    }
};
