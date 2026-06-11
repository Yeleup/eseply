<?php

use App\BillingPeriodStatus;
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
        Schema::create('billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->string('status')->default(BillingPeriodStatus::Open->value);
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('active_clients_count')->default(0);
            $table->unsignedInteger('created_accruals_count')->default(0);
            $table->unsignedInteger('skipped_accruals_count')->default(0);
            $table->unsignedInteger('failed_clients_count')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'starts_on'], 'billing_periods_org_starts_unique');
            $table->index(['organization_id', 'status'], 'billing_periods_org_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_periods');
    }
};
