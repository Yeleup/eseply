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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('account_number');
            $table->string('name');
            $table->string('client_type')->default('individual');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->decimal('starting_balance', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'account_number']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
