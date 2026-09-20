<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — wartości cech na stanowisku (ADR-011, opcja A).
 *
 * ⚠️ Trzy kolumny typowane zamiast jednej tekstowej. Powód jest jeden i decydujący:
 * filtrowanie po liczbie ma być zwykłym porównaniem korzystającym z indeksu, a nie
 * rzutowaniem tekstu przy każdym wierszu. Wybór z listy jest kluczem obcym, więc
 * opcja skasowana ze słownika nie zostawia osieroconego napisu.
 *
 * ⚠️ Reguła „dokładnie jedna z trzech wypełniona, zgodnie z `type` cechy" NIE MA
 * odpowiednika w schemacie — jej jedynym domem jest `app/Rules/PositionAttributeValueMatchesType`,
 * wołana z każdego miejsca zapisu, także z akcji zbiorczej. Druga kopia tego
 * warunku jest defektem, nie zabezpieczeniem (ADR-011).
 *
 * ⚠️ BRAK WIERSZA to trzeci stan, nie „nie": cecha niewypełniona oznacza „nikt się
 * nie wypowiedział" i nie bierze udziału w filtrowaniu w żadną stronę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('value_flag')->nullable();
            $table->decimal('value_number', 8, 2)->nullable();
            $table->foreignId('position_attribute_option_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->timestamps();

            // ⚠️ Jawna nazwa indeksu, bo domyślna
            // (`position_attribute_values_position_id_position_attribute_id_unique`)
            // ma 66 znaków, a MySQL dopuszcza 64. Objaw jest mylący: `Schema::create`
            // przechodzi, tabela zostaje, wywala się dopiero indeks — migracja nie
            // zapisuje się w `migrations`, a ponowny przebieg mówi „table already exists".
            $table->unique(['position_id', 'position_attribute_id'], 'pav_position_attribute_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_attribute_values');
    }
};
