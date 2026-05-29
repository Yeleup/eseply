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
        Schema::table('accruals', function (Blueprint $table) {
            $table->decimal('adjustment_amount', 14, 2)->default(0)->after('paid_amount');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('adjustment_amount', 14, 2)->default(0)->after('paid_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('adjustment_amount');
        });

        Schema::table('accruals', function (Blueprint $table) {
            $table->dropColumn('adjustment_amount');
        });
    }
};
