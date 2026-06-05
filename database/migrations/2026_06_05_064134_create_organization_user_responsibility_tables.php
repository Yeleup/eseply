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
        Schema::create('organization_user_regions', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['organization_id', 'user_id', 'region_id'], 'org_user_regions_primary');
            $table->foreign(['organization_id', 'user_id'], 'org_user_regions_member_foreign')
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'region_id'], 'org_user_regions_org_region_idx');
        });

        Schema::create('organization_user_streets', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('street_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['organization_id', 'user_id', 'street_id'], 'org_user_streets_primary');
            $table->foreign(['organization_id', 'user_id'], 'org_user_streets_member_foreign')
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'street_id'], 'org_user_streets_org_street_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_user_streets');
        Schema::dropIfExists('organization_user_regions');
    }
};
