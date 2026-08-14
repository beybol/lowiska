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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('fisheries', function (Blueprint $table) {
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null');
            $table->string('bank_account_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn(['currency_id', 'bank_account_number']);
        });

        Schema::dropIfExists('currencies');
    }
};
