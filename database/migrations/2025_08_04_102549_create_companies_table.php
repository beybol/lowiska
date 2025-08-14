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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('is_verified')->default(false);
            $table->string('name');
            $table->string('tin')->nullable();
            $table->string('renae')->nullable();
            $table->string('street');
            $table->string('house_number');
            $table->string('flat_number')->nullable();
            $table->string('postal_code');
            $table->string('city');
            $table->foreignId('state_id')->constrained()->onDelete('cascade');
            $table->string('cso_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
