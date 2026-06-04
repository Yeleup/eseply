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
        Schema::table('clients', function (Blueprint $table) {
            $table->text('iin')->nullable()->after('name');
            $table->text('contract')->nullable()->after('phone');
            $table->text('technical_conditions')->nullable()->after('contract');
            $table->unsignedInteger('residents_count')->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'iin',
                'contract',
                'technical_conditions',
            ]);
            $table->unsignedInteger('residents_count')->default(0)->change();
        });
    }
};
