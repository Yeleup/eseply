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
        Schema::create('accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_period_id')->constrained()->restrictOnDelete();
            $table->string('account_number');
            $table->string('client_name');
            $table->string('utility_service_name')->nullable();
            $table->string('billing_type');
            $table->decimal('volume', 14, 4)->nullable();
            $table->decimal('tariff_price', 14, 2)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2)->default(0);
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['billing_period_id', 'client_id'], 'accruals_period_client_unique');
            $table->index(['organization_id', 'billing_period_id'], 'accruals_org_period_idx');
            $table->index(['organization_id', 'account_number'], 'accruals_org_account_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accruals');
    }
};
