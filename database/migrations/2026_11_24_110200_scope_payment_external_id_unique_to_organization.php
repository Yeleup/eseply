<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Kaspi number is unique inside one organization's contract only, so the
 * provider number of a payment is unique per organization, not globally.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'external_provider', 'external_payment_id'],
                'payments_org_provider_external_id_unique',
            );
            $table->dropUnique('payments_provider_external_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['external_provider', 'external_payment_id'], 'payments_provider_external_id_unique');
            $table->dropUnique('payments_org_provider_external_id_unique');
        });
    }
};
