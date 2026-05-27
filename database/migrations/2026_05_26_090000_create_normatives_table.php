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
        Schema::create('normatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utility_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('tariff_category_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 14, 4);
            $table->string('calculation_type');
            $table->date('starts_on');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'utility_service_id', 'tariff_category_id', 'status'], 'normatives_lookup_idx');
            $table->index(['organization_id', 'calculation_type', 'starts_on'], 'normatives_calc_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('normatives');
    }
};
