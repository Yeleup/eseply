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
        if (Schema::hasColumn('clients', 'billing_type')) {
            DB::table('clients')
                ->where('billing_type', 'normative')
                ->update(['billing_type' => 'per_person']);

            $this->changeBillingTypeDefault();
        }

        $this->dropColumnWithForeign('clients', 'tariff_category_id');

        if (Schema::hasTable('tariffs')) {
            $this->addTariffColumns();
            $this->copyOldTariffPriceToUnitPrice();
            $this->dropIndexIfExists('tariffs', 'tariffs_lookup_idx');
            $this->dropColumnWithForeign('tariffs', 'tariff_category_id');
            $this->dropColumnIfExists('tariffs', 'price');
            $this->addIndexIfPossible('tariffs', ['organization_id', 'utility_service_id', 'client_type', 'status'], 'tariffs_lookup_idx');
        }

        Schema::dropIfExists('normatives');
        Schema::dropIfExists('tariff_categories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }

    private function changeBillingTypeDefault(): void
    {
        try {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('billing_type')->default('per_person')->change();
            });
        } catch (Throwable) {
            //
        }
    }

    private function addTariffColumns(): void
    {
        if (! Schema::hasColumn('tariffs', 'client_type')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->string('client_type')->default('individual')->after('utility_service_id');
            });
        }

        if (! Schema::hasColumn('tariffs', 'unit_price')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->decimal('unit_price', 14, 2)->nullable()->after('client_type');
            });
        }

        if (! Schema::hasColumn('tariffs', 'per_person_price')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->decimal('per_person_price', 14, 2)->nullable()->after('unit_price');
            });
        }
    }

    private function copyOldTariffPriceToUnitPrice(): void
    {
        if (! Schema::hasColumn('tariffs', 'price') || ! Schema::hasColumn('tariffs', 'unit_price')) {
            return;
        }

        DB::table('tariffs')
            ->whereNull('unit_price')
            ->update(['unit_price' => DB::raw('price')]);
    }

    private function dropColumnWithForeign(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
            });
        } catch (Throwable) {
            //
        }

        $this->dropColumnIfExists($tableName, $column);
    }

    private function dropColumnIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfPossible(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        } catch (Throwable) {
            //
        }
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        } catch (Throwable) {
            //
        }
    }
};
