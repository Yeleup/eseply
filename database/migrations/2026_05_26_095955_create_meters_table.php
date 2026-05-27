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
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_service_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('active_client_id')->nullable();
            $table->string('number');
            $table->date('installed_on')->nullable();
            $table->decimal('initial_reading', 14, 4)->default(0);
            $table->date('removed_on')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'meters_org_number_unique');
            $table->unique(['organization_id', 'active_client_id'], 'meters_one_active_per_client_unique');
            $table->index(['organization_id', 'client_id', 'status'], 'meters_org_client_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meters');
    }
};
