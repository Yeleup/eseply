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
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period', 6);
            $table->decimal('previous_reading', 14, 4)->default(0);
            $table->decimal('current_reading', 14, 4);
            $table->decimal('consumption', 14, 4)->default(0);
            $table->date('read_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['meter_id', 'period'], 'meter_readings_meter_period_unique');
            $table->index(['organization_id', 'period'], 'meter_readings_org_period_idx');
            $table->index(['organization_id', 'client_id'], 'meter_readings_org_client_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
