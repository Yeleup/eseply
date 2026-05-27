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
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('accrual_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number');
            $table->string('period', 6);
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
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('accrual_id');
            $table->unique(['organization_id', 'client_id', 'period'], 'receipts_client_period_unique');
            $table->unique(['organization_id', 'receipt_number'], 'receipts_org_number_unique');
            $table->index(['organization_id', 'period'], 'receipts_org_period_idx');
            $table->index(['organization_id', 'account_number'], 'receipts_org_account_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
