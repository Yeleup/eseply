<?php

use App\Support\KaspiUidBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaspi online settings of an organization: whether it accepts payments and the
 * permanent uid of its Kaspi address `/kaspi/{uid}`.
 *
 * Every existing organization gets its uid here, once; a new organization gets
 * one when it is created. The uid never changes afterwards.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('kaspi_uid')->nullable()->after('note');
            $table->boolean('kaspi_enabled')->default(false)->after('kaspi_uid');
        });

        KaspiUidBackfill::run();

        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('kaspi_uid')->nullable(false)->change();
            $table->unique('kaspi_uid', 'organizations_kaspi_uid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique('organizations_kaspi_uid_unique');
            $table->dropColumn(['kaspi_uid', 'kaspi_enabled']);
        });
    }
};
