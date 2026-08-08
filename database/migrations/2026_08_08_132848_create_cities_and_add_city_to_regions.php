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
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->unique(['organization_id', 'name'], 'cities_org_name_unique');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('city_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $organizationIds = DB::table('regions')
            ->whereNull('city_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $cityId = DB::table('cities')->insertGetId([
                'organization_id' => $organizationId,
                'name' => 'Город',
            ]);

            DB::table('regions')
                ->where('organization_id', $organizationId)
                ->whereNull('city_id')
                ->update(['city_id' => $cityId]);
        }

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable(false)->change();
            $table->index('organization_id', 'regions_organization_id_index');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropUnique('regions_org_name_unique');
            $table->unique(['city_id', 'name'], 'regions_city_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->unique(['organization_id', 'name'], 'regions_org_name_unique');
            $table->dropUnique('regions_city_name_unique');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->dropIndex('regions_organization_id_index');
            $table->dropColumn('city_id');
        });

        Schema::dropIfExists('cities');
    }
};
