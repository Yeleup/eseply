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
        if (! Schema::hasColumn('clients', 'area')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('clients', 'area')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('area', 10, 2)->default(0)->after('residents_count');
        });
    }
};
