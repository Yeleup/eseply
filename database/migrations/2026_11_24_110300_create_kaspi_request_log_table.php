<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every request that came from Kaspi to a known organization, the rejected ones
 * too, with the moment it was received and the code it got. It is not the
 * journal: a rejected `pay` still creates no operation. The profile and the
 * journal tiles read the last request and the answers with code 5 of the last
 * 24 hours from here, to the second.
 *
 * Without foreign keys, so writing a row never waits for a lock of another
 * table; rows are kept for 30 days, the latest row of an organization always.
 * Every query starts with the organization: the last request and the old rows
 * to prune by (organization, time), the errors of the last 24 hours by
 * (organization, code, time).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kaspi_request_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->dateTime('received_at');
            $table->unsignedTinyInteger('result');

            $table->index(['organization_id', 'received_at'], 'kaspi_request_log_org_received_idx');
            $table->index(['organization_id', 'result', 'received_at'], 'kaspi_request_log_org_result_received_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kaspi_request_log');
    }
};
