<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_item_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['cost_item_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_item_services');
    }
};
