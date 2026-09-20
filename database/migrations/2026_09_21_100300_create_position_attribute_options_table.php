<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — dopuszczalne wartości cechy typu `choice`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_attribute_options');
    }
};
