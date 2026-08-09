<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
