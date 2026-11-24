<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Running totals of a subscriber's month — payments, adjustments, opening and
 * closing balance — grow from `DECIMAL(14,2)` to `DECIMAL(18,2)`.
 *
 * One payment from Kaspi may be up to 999 999 999 999,99 ₸ (`^\d{1,12}\.\d{2}$`),
 * so two of them already do not fit a 14-digit total. 18 digits hold ten
 * thousand such payments. Widening keeps every stored value as it is.
 *
 * One `ALTER TABLE` per table changes all four columns at once, so a large
 * table is rebuilt once, with the same `NOT NULL DEFAULT 0` as before.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['receipts', 'accruals'];

    /**
     * @var list<string>
     */
    private array $columns = ['paid_amount', 'adjustment_amount', 'opening_balance', 'closing_balance'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement($this->alterStatement($table, 18));
        }
    }

    /**
     * Reverse the migrations.
     *
     * Every column of both tables is checked before the first `ALTER`: a total
     * that no longer fits 14 digits cannot be kept, and the rollback stops with
     * nothing changed.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            foreach ($this->columns as $column) {
                if (DB::table($table)->whereRaw("abs(`{$column}`) >= 1000000000000")->exists()) {
                    throw new RuntimeException("Откат невозможен: в {$table}.{$column} есть суммы, которые не помещаются в DECIMAL(14,2).");
                }
            }
        }

        foreach ($this->tables as $table) {
            DB::statement($this->alterStatement($table, 14));
        }
    }

    private function alterStatement(string $table, int $precision): string
    {
        $modifications = array_map(
            fn (string $column): string => "modify `{$column}` decimal({$precision}, 2) not null default 0",
            $this->columns,
        );

        return "alter table `{$table}` ".implode(', ', $modifications);
    }
};
