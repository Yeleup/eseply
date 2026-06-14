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
            $table->string('iin', 12)->nullable()->change();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unique(['organization_id', 'iin'], 'clients_org_iin_unique');
            $table->unique(['organization_id', 'phone'], 'clients_org_phone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_org_iin_unique');
            $table->dropUnique('clients_org_phone_unique');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->text('iin')->nullable()->change();
        });
    }
};
