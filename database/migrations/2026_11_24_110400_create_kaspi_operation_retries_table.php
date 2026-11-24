<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeats of a Kaspi operation, written at once when Kaspi repeats a `txn_id`
 * and folded into the journal row later (count, time, last code, mismatch).
 *
 * A repeat must answer in time even while a posting holds the journal row, and
 * still never lose the fact that it came. A row here is inserted without
 * touching the journal row — no foreign key, so no lock to wait for — and is
 * folded under the lock of the journal row as soon as it is free.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kaspi_operation_retries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kaspi_operation_id');
            $table->dateTime('received_at');
            $table->unsignedTinyInteger('result');
            $table->boolean('mismatch');

            $table->index('kaspi_operation_id', 'kaspi_operation_retries_operation_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kaspi_operation_retries');
    }
};
