<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 016 — blokady i ograniczenia czasowe, jeden byt z polem skutku.
 *
 * ⚠️ `selection_kind` i `selection_label` opisują wyłącznie to, JAK wybrano zbiór.
 * Sam zbiór jest materializowany w `availability_block_position` i NIE jest
 * rozwiązywany przy odczycie — inaczej zmiana cechy na jednym stanowisku wciągałaby
 * je po cichu w ograniczenie albo z niego wypychała.
 *
 * ⚠️ `fishery_id` wymagany i kasowany kaskadowo — jak `sale_periods` i `position_groups`:
 * wpis nie ma wartości historycznej ani ścieżki dostępu poza łowiskiem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 20);
            $table->foreignId('position_attribute_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason');
            $table->boolean('reason_visible')->default(true);
            $table->string('selection_kind', 20);
            $table->string('selection_label')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fishery_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_blocks');
    }
};
