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
            $table->foreignId('utility_service_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('billing_type')->default('normative')->after('tariff_category_id');
            $table->unsignedInteger('residents_count')->default(0)->after('billing_type');
            $table->decimal('area', 10, 2)->default(0)->after('residents_count');
            $table->decimal('fixed_amount', 14, 2)->default(0)->after('area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('utility_service_id');
            $table->dropColumn([
                'billing_type',
                'residents_count',
                'area',
                'fixed_amount',
            ]);
        });
    }
};
