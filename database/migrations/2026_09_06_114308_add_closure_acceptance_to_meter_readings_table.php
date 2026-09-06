<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->string('closure_resolution')->nullable();
            $table->foreignId('closure_accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closure_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closure_accepted_by_user_id');
            $table->dropColumn(['closure_resolution', 'closure_accepted_at']);
        });
    }
};
