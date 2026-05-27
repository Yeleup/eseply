<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utility_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('tariff_category_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 14, 2);
            $table->date('starts_on');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'utility_service_id', 'tariff_category_id', 'status'], 'tariffs_lookup_idx');
            $table->index(['organization_id', 'starts_on'], 'tariffs_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
