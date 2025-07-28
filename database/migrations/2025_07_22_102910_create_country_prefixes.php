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
        Schema::create('country_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix')->nullable();
            $table->string('country_name')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table
                ->foreignId('country_prefix_id')
                ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('country_prefixes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['country_prefix_id', 'phone']);
        });
    }
};
