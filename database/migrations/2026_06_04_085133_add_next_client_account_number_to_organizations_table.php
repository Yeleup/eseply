<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIRST_AUTOMATIC_ACCOUNT_NUMBER = 100001;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'next_client_account_number')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('next_client_account_number')
                ->default(self::FIRST_AUTOMATIC_ACCOUNT_NUMBER)
                ->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'next_client_account_number')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('next_client_account_number');
        });
    }
};
