<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 018 — cennik jako LISTA REGUŁ Z WARUNKAMI, nie tabela stawek po wymiarach
 * (ADR-014).
 *
 * ⚠️ Dodanie wymiaru cennika jest dodaniem WARUNKU, a nie kolumny: progi za kolejne osoby,
 * dopłata za wędkę czy cena per stanowisko zmieszczą się tu bez migracji. Tabela stawek
 * kluczowana wymiarami wymagałaby migracji za każdym razem — i to jest jedyny powód,
 * dla którego ten model wygląda na ogólniejszy, niż dzisiejsze potrzeby wymagają.
 *
 * ⚠️ **Dwa wymiary czasu, których nie wolno zlać:**
 * - `effective_from`/`effective_to` — czy ten ZAPIS bierze dziś udział w wycenie
 *   (mierzone wobec dzisiejszej daty w strefie łowiska);
 * - `first_day_on`/`last_day_on` — których DÓB reguła dotyczy (mierzone wobec wycenianej doby).
 *
 * Bez tego rozdzielenia nie da się zaplanować zmiany ceny z wyprzedzeniem, bo każda zmiana
 * działałaby natychmiast.
 *
 * ⚠️ **Nazwy `first_day_on`/`last_day_on` są celowe i identyczne jak w `whole_term_periods`** —
 * oba pola wskazują DNI ROZPOCZĘCIA DÓB. `starts_on`/`ends_on` znaczą w tym projekcie co innego
 * (`sale_periods.ends_on` to ostatni dzień okna, a ostatnia sprzedawalna doba zaczyna się dzień
 * wcześniej), więc użycie ich tutaj byłoby zaproszeniem do skopiowania złej logiki granic
 * (`docs/conventions/dostepnosc.md` §4).
 *
 * `fishery_id` wymagany z kaskadą, jak `sale_periods` i `whole_term_periods`: reguła cenowa nie
 * ma wartości historycznej ani ścieżki dostępu poza łowiskiem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();

            // `rate` ZASTĘPUJE stawkę, `surcharge` DODAJE się — jedna mechanika z flagą (O3).
            $table->string('kind');
            $table->string('label')->nullable();
            $table->decimal('amount', 8, 2);
            $table->integer('priority')->default(0);
            $table->boolean('is_suspended')->default(false);

            // Wymiar 1: obowiązywanie zapisu.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // Wymiar 2 i pozostałe osie warunku. Każda `nullable` — pusta oś znaczy
            // „bez warunku na tej osi", NIE „warunek fałszywy". Reguła `rate` bez ani
            // jednego warunku jest stawką bazową łowiska.
            $table->json('weekdays')->nullable();
            $table->date('first_day_on')->nullable();
            $table->date('last_day_on')->nullable();
            $table->unsignedTinyInteger('anglers_count')->nullable();
            $table->string('participant_role')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['fishery_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
