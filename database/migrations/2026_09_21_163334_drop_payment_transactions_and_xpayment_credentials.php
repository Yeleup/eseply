<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the remote Kaspi payment through XPayment: its payment requests and the
 * organization API keys. Payments already recorded with the `kaspi` method stay.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('payment_transactions');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('xpayment_api_key');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the structure only; the dropped requests and API keys are not recovered.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('xpayment_api_key')->nullable()->after('note');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('provider')->default('xpayment')->index();
            $table->string('merchant_order_id')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('external_payment_id')->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending')->index();
            $table->text('qr_url')->nullable();
            $table->string('payer_phone')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'billing_period_id', 'status'], 'payment_tx_org_period_status_idx');
            $table->index(['organization_id', 'client_id', 'billing_period_id'], 'payment_tx_org_client_period_idx');

            $table->foreign(['billing_period_id', 'organization_id'], 'payment_transactions_period_organization_foreign')
                ->references(['id', 'organization_id'])
                ->on('billing_periods')
                ->restrictOnDelete();
        });
    }
};
