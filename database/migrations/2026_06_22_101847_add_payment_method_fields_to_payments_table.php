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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method')->default('cash')->after('billing_period_id')->index();
            $table->string('external_provider')->nullable()->after('method')->index();
            $table->string('external_payment_id')->nullable()->after('external_provider');
            $table->foreignId('received_by_user_id')
                ->nullable()
                ->after('external_payment_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->unique(['external_provider', 'external_payment_id'], 'payments_provider_external_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_external_id_unique');
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn([
                'method',
                'external_provider',
                'external_payment_id',
                'received_by_user_id',
            ]);
        });
    }
};
