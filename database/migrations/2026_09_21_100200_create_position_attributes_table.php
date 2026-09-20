<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — słownik cech stanowisk, wspólny dla całego portalu.
 *
 * ⚠️ Nie ma tu kolumny `fishery_id` i to jest decyzja, nie przeoczenie: cechy
 * własne łowiska są odrzucone co do zasady (słownik definiowany przez operatorów
 * przestałby być wspólny, a filtr przez wszystkie łowiska straciłby sens).
 * Pusta furtka przygotowawcza z czasem zostałaby użyta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->string('unit', 20)->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_attributes');
    }
};
