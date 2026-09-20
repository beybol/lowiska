<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — grupa stanowisk.
 *
 * ⚠️ Grupa jest ETYKIETĄ, nie poziomem hierarchii: nie niesie cech ani stanu.
 * ⚠️ `fishery_id` wymagany i kasowany kaskadowo — inaczej niż w `positions`.
 * Stanowisko po odcięciu od łowiska wciąż wisi w tabelach pośrednich pozwoleń
 * i usług, więc coś znaczy; grupa po odcięciu nie znaczy nic (zadanie 014,
 * „Rozstrzygnięcia").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_groups');
    }
};
