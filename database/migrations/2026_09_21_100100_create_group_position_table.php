<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — przypisanie stanowisk do grup, wiele-do-wielu.
 *
 * Nazwa tabeli ustawiona jawnie: domyślna (`position_position_group`) jest
 * nieczytelna, więc modele wskazują `group_position` wprost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_position', function (Blueprint $table) {
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_group_id')->constrained()->cascadeOnDelete();

            $table->primary(['position_id', 'position_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_position');
    }
};
