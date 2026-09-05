<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A controller who reached the meter but could not read it — sealed, no access,
 * a dog in the yard — has to be able to leave a photo and a note against that
 * meter and that month. The row is the same one the reading will later be
 * written into, so the note stays attached to the reading it explains.
 *
 * An empty `current_reading` is therefore not a reading: it never counts as
 * taken, never contributes consumption, never becomes the previous reading of
 * the next month and never lets the month close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->bigInteger('current_reading')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A row without a value cannot survive a NOT NULL column, and guessing a
        // number for it would invent a reading nobody took.
        DB::table('meter_readings')->whereNull('current_reading')->delete();

        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->bigInteger('current_reading')->nullable(false)->change();
        });
    }
};
