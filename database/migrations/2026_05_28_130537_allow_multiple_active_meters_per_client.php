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
        if (! Schema::hasColumn('meters', 'active_client_id')) {
            return;
        }

        Schema::table('meters', function (Blueprint $table) {
            $table->dropUnique('meters_one_active_per_client_unique');
            $table->dropColumn('active_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('meters', 'active_client_id')) {
            return;
        }

        Schema::table('meters', function (Blueprint $table) {
            $table->unsignedBigInteger('active_client_id')->nullable()->after('utility_service_id');
            $table->unique(['organization_id', 'active_client_id'], 'meters_one_active_per_client_unique');
        });
    }
};
