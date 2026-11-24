<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Running totals of a subscriber's month — payments, adjustments, opening and
 * closing balance — grow from `DECIMAL(14,2)` to `DECIMAL(18,2)`.
 *
 * One payment from Kaspi may be up to 999 999 999 999,99 ₸ (`^\d{1,12}\.\d{2}$`),
 * so two of them already do not fit a 14-digit total. 18 digits hold ten
 * thousand such payments. Widening keeps every stored value as it is.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = ['paid_amount', 'adjustment_amount', 'opening_balance', 'closing_balance'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['receipts', 'accruals'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach ($this->columns as $column) {
                    $table->decimal($column, 18, 2)->default(0)->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Fails if a total no longer fits 14 digits: such a total cannot be kept.
     */
    public function down(): void
    {
        foreach (['receipts', 'accruals'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach ($this->columns as $column) {
                    $table->decimal($column, 14, 2)->default(0)->change();
                }
            });
        }
    }
};
