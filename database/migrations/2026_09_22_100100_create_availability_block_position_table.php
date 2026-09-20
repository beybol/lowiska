<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 016 — rozwiązany, ZMATERIALIZOWANY zbiór stanowisk objętych wpisem.
 *
 * Także przy wyborze „całe łowisko" wpis trzyma listę konkretnych stanowisk.
 * Skutek jest zamierzony: stanowisko dodane później NIE wchodzi do istniejącej
 * blokady, a panel o tym ostrzega przy jego zakładaniu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_block_position', function (Blueprint $table) {
            $table->foreignId('availability_block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();

            // ⚠️ Jawna nazwa: domyślna przekroczyłaby limit 64 znaków MySQL-a
            // (lekcja z `position_attribute_values` w zadaniu 014).
            $table->primary(['availability_block_id', 'position_id'], 'abp_block_position_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_block_position');
    }
};
