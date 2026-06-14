<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['accrual_id']);
            $table->dropUnique('receipts_accrual_id_unique');
            $table->unsignedBigInteger('accrual_id')->nullable()->change();
            $table->unique('accrual_id');
            $table->foreign('accrual_id')->references('id')->on('accruals')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('receipts')->whereNull('accrual_id')->delete();

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['accrual_id']);
            $table->dropUnique('receipts_accrual_id_unique');
            $table->unsignedBigInteger('accrual_id')->nullable(false)->change();
            $table->unique('accrual_id');
            $table->foreign('accrual_id')->references('id')->on('accruals')->cascadeOnDelete();
        });
    }
};
