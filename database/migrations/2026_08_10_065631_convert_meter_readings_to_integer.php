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
        DB::table('meters')->update([
            'initial_reading' => DB::raw('ROUND(initial_reading)'),
        ]);

        DB::table('meter_readings')->update([
            'previous_reading' => DB::raw('ROUND(previous_reading)'),
            'current_reading' => DB::raw('ROUND(current_reading)'),
            'consumption' => DB::raw('ROUND(consumption)'),
        ]);

        Schema::table('meters', function (Blueprint $table): void {
            $table->bigInteger('initial_reading')->default(0)->change();
        });

        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->bigInteger('previous_reading')->default(0)->change();
            $table->bigInteger('current_reading')->change();
            $table->bigInteger('consumption')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table): void {
            $table->decimal('initial_reading', 14, 4)->default(0)->change();
        });

        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->decimal('previous_reading', 14, 4)->default(0)->change();
            $table->decimal('current_reading', 14, 4)->change();
            $table->decimal('consumption', 14, 4)->default(0)->change();
        });
    }
};
