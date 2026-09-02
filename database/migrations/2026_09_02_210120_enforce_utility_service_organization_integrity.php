<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps `utility_service_id` inside the organization that owns the row.
 *
 * Every table below carries both `organization_id` and `utility_service_id`, and the
 * models already overwrite the service with the one of the organization on save. A
 * bulk `INSERT` bypasses the models, and a row pointing at the service of another
 * organization then disappears from every service scoped consumer: the month closure
 * finds no meters and fails with «Не найдены активные счётчики по услуге организации»,
 * and the receipt finds no tariff and is written with a zero amount.
 *
 * A composite foreign key moves the rule into the database, so it holds for plain SQL
 * too.
 *
 * Unlike the billing period of a row, the correct service is not ambiguous: an
 * organization owns exactly one service, enforced by the unique `organization_id` of
 * `utility_services`. Misattributed rows are therefore repaired instead of aborting
 * the migration, and only a row whose organization has no service at all is left to
 * the operator.
 *
 * The delete rule is `restrict` everywhere, including the four tables that use
 * `set null` on `utility_service_id` alone: a composite `set null` would also null
 * `organization_id`, which is not nullable. A service that is still in use can no
 * longer be deleted, which matches the module rule that an organization keeps one
 * service for its whole life.
 */
return new class extends Migration
{
    /**
     * Tables guarded by the composite key, mapped to whether their
     * `utility_service_id` may be null.
     *
     * @var array<string, bool>
     */
    private const array GUARDED_TABLES = [
        'accruals' => true,
        'clients' => true,
        'meter_readings' => true,
        'meters' => true,
        'tariffs' => false,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->repairMisattributedRows();
        $this->assertEveryRowIsRepaired();

        Schema::table('utility_services', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'utility_services_id_organization_unique');
        });

        foreach (array_keys(self::GUARDED_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint
                    ->foreign(['utility_service_id', 'organization_id'], $this->constraintName($table))
                    ->references(['id', 'organization_id'])
                    ->on('utility_services')
                    ->restrictOnDelete();
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

        Schema::table('utility_services', function (Blueprint $table): void {
            $table->dropUnique('utility_services_id_organization_unique');
        });
    }

    /**
     * Point every row at the service of its own organization, and clear the
     * reference of a row whose organization has no service to point at.
     *
     * Public so the repair can be exercised on its own, without the schema
     * changes of `up()`.
     */
    public function repairMisattributedRows(): void
    {
        foreach (self::GUARDED_TABLES as $table => $isNullable) {
            DB::table($table)
                ->join('utility_services', 'utility_services.organization_id', '=', $table.'.organization_id')
                ->whereColumn($table.'.utility_service_id', '!=', 'utility_services.id')
                ->update([$table.'.utility_service_id' => DB::raw('utility_services.id')]);

            if (! $isNullable) {
                continue;
            }

            $this->misattributedRows($table)->update(['utility_service_id' => null]);
        }
    }

    /**
     * A row can survive the repair only when its organization has no service at
     * all and the column is not nullable, because there is then nothing to point
     * it at and nothing to clear it with. Adding the key to a table that still
     * breaks the rule fails with a bare SQL error, so such rows are counted and
     * reported per table instead.
     */
    private function assertEveryRowIsRepaired(): void
    {
        $mismatches = [];

        foreach (array_keys(self::GUARDED_TABLES) as $table) {
            $count = $this->misattributedRows($table)->count();

            if ($count > 0) {
                $mismatches[] = "{$table}: {$count}";
            }
        }

        if ($mismatches === []) {
            return;
        }

        throw new RuntimeException(
            'Есть строки, у которых услуга принадлежит другой организации, а у собственной организации услуги нет, '
            .'поэтому связь нельзя включить. Заведите услугу организации и повторите миграцию. Найдено — '
            .implode(', ', $mismatches).'.'
        );
    }

    /**
     * Rows whose service is not the service of their own organization.
     *
     * A row without a service satisfies the composite key, so it is not counted.
     */
    private function misattributedRows(string $table): Builder
    {
        return DB::table($table)
            ->whereNotNull($table.'.utility_service_id')
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('utility_services')
                ->whereColumn('utility_services.id', $table.'.utility_service_id')
                ->whereColumn('utility_services.organization_id', $table.'.organization_id'));
    }

    private function constraintName(string $table): string
    {
        return $table.'_utility_service_organization_foreign';
    }
};
