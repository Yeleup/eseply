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
        if (! Schema::hasColumn('clients', 'region_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('region_id')
                    ->nullable()
                    ->after('phone')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('clients', 'street_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('street_id')
                    ->nullable()
                    ->after('region_id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'house')) {
                $table->string('house')->nullable()->after('street_id');
            }

            if (! Schema::hasColumn('clients', 'apartment')) {
                $table->string('apartment')->nullable()->after('house');
            }
        });

        if (Schema::hasColumn('clients', 'address')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'address')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('address')->nullable()->after('phone');
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'street_id')) {
                $table->dropConstrainedForeignId('street_id');
            }

            if (Schema::hasColumn('clients', 'region_id')) {
                $table->dropConstrainedForeignId('region_id');
            }

            if (Schema::hasColumn('clients', 'house')) {
                $table->dropColumn('house');
            }

            if (Schema::hasColumn('clients', 'apartment')) {
                $table->dropColumn('apartment');
            }
        });
    }
};
