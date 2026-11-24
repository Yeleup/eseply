<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests from Kaspi per organization and hour: every request that came from
 * Kaspi to a known organization counts, the rejected ones too, without being
 * an operation of the journal. The profile and the journal tiles read the last
 * request and the answers with code 5 from here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kaspi_request_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->dateTime('hour_start');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->dateTime('last_request_at');
            $table->timestamps();

            $table->unique(['organization_id', 'hour_start'], 'kaspi_request_stats_org_hour_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kaspi_request_stats');
    }
};
