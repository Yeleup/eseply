<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal of Kaspi online: one row per Kaspi operation (organization + txn_id),
 * kept forever, so a repeated request always finds the answer to the first one.
 * Neither its subscriber nor its organization can be deleted while it exists.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kaspi_operations', function (Blueprint $table) {
            $table->id();
            // Kept forever: an organization with Kaspi operations cannot be deleted.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('command', 8);
            $table->string('txn_id', 18);
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('account', 10);
            $table->decimal('sum', 14, 2)->nullable();
            $table->dateTime('txn_date')->nullable();
            $table->decimal('debt', 14, 2)->nullable();
            $table->timestamp('received_at');
            $table->string('state', 16);
            $table->string('state_reason')->nullable();
            $table->unsignedTinyInteger('last_result');
            $table->text('response')->nullable();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('retries_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->boolean('retry_mismatch')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'txn_id'], 'kaspi_operations_org_txn_unique');
            $table->index(['organization_id', 'txn_date'], 'kaspi_operations_org_txn_date_idx');
            $table->index(['organization_id', 'state'], 'kaspi_operations_org_state_idx');
            $table->index(['organization_id', 'received_at'], 'kaspi_operations_org_received_idx');
            $table->index(['organization_id', 'account'], 'kaspi_operations_org_account_idx');
            $table->index(['state', 'received_at'], 'kaspi_operations_state_received_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kaspi_operations');
    }
};
