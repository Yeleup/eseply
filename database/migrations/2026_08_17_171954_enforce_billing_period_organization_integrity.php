<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps `billing_period_id` inside the organization that owns the row.
 *
 * Every table below carries both `organization_id` and `billing_period_id`, and the
 * models already refuse to save a row whose billing period belongs to another
 * organization. A bulk `INSERT` or `UPDATE` bypasses the models, and such a row is
 * then invisible to every period scoped consumer: reports show it as zero and the
 * month closure never applies it.
 *
 * A composite foreign key moves the rule into the database, so it holds for plain SQL
 * too. The delete rule of each composite key repeats the delete rule the table already
 * has on `billing_period_id`, so deleting a billing period or an organization behaves
 * exactly as before.
 */
return new class extends Migration
{
    /**
     * Tables guarded by the composite key, mapped to the delete rule they already use.
     *
     * @var array<string, 'cascade'|'restrict'>
     */
    private const array GUARDED_TABLES = [
        'accruals' => 'restrict',
        'balance_adjustments' => 'restrict',
        'billing_period_closure_errors' => 'cascade',
        'meter_readings' => 'restrict',
        'payment_transactions' => 'restrict',
        'payments' => 'restrict',
        'receipts' => 'restrict',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoMisattributedRows();

        Schema::table('billing_periods', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'billing_periods_id_organization_unique');
        });

        foreach (self::GUARDED_TABLES as $table => $deleteRule) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $deleteRule): void {
                $foreignKey = $blueprint
                    ->foreign(['billing_period_id', 'organization_id'], $this->constraintName($table))
                    ->references(['id', 'organization_id'])
                    ->on('billing_periods');

                $deleteRule === 'cascade'
                    ? $foreignKey->cascadeOnDelete()
                    : $foreignKey->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_keys(self::GUARDED_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign($this->constraintName($table));
            });
        }

        Schema::table('billing_periods', function (Blueprint $table): void {
            $table->dropUnique('billing_periods_id_organization_unique');
        });
    }

    /**
     * Adding the key to a table that already breaks the rule fails with a bare SQL error,
     * so the offending rows are counted first and reported per table.
     */
    private function assertNoMisattributedRows(): void
    {
        $mismatches = [];

        foreach (array_keys(self::GUARDED_TABLES) as $table) {
            $count = DB::table($table)
                ->join('billing_periods', 'billing_periods.id', '=', $table.'.billing_period_id')
                ->whereColumn($table.'.organization_id', '!=', 'billing_periods.organization_id')
                ->count();

            if ($count > 0) {
                $mismatches[] = "{$table}: {$count}";
            }
        }

        if ($mismatches === []) {
            return;
        }

        throw new RuntimeException(
            'Есть строки, у которых расчётный месяц принадлежит другой организации, поэтому связь нельзя включить. '
            .'Исправьте данные и повторите миграцию. Найдено — '.implode(', ', $mismatches).'.'
        );
    }

    private function constraintName(string $table): string
    {
        return $table.'_period_organization_foreign';
    }
};
