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
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_code')->nullable()
                ->comment('Kod do autentykacji dwuetapowej');
            $table->datetime('two_factor_expires_at')->nullable()
                ->comment('Data wygaśnięcia tokenu autoryzacji dwuetapowej');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumns(['two_factor_code', 'two_factor_expires_at']);
        });
    }
};
