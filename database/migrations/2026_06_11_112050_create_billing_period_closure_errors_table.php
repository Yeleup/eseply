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
        Schema::create('billing_period_closure_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_number')->nullable();
            $table->string('client_name')->nullable();
            $table->string('billing_type')->nullable();
            $table->string('code');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['billing_period_id', 'code'], 'bp_closure_errors_period_code_idx');
            $table->index(['organization_id', 'billing_period_id'], 'bp_closure_errors_org_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_period_closure_errors');
    }
};
