<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 018 — cennik jako LISTA REGUŁ, nie tabela stawek po wymiarach (ADR-014).
 *
 * ⚠️ Dodanie wymiaru cennika jest dodaniem WARUNKU, a nie kolumny: progi za kolejne osoby,
 * dopłata za wędkę czy cena per stanowisko zmieszczą się tu bez migracji. Tabela stawek
 * kluczowana wymiarami wymagałaby migracji za każdym razem.
 *
 * ⚠️ **Osie warunku rozkładają się ASYMETRYCZNIE i to jest sedno przedefiniowania z 22.09.2026**
 * (ADR-014, sekcja „Aktualizacja"):
 * - **stawka** (`rate`) ma wyłącznie zakres dat — żadnych dni tygodnia, obsady ani roli;
 * - **dopłata** (`surcharge`) niesie cały ciężar warunkowy: daty, dni tygodnia, obsadę
 *   i `applies_to`.
 *
 * Powód: cena bazowa u obu znanych łowisk jest identyczna przez cały tydzień, a warunki na
 * stawce zmieniały cenę bazową bez śladu. Kto chce różnicować cenę dniami — robi to dopłatą.
 *
 * ⚠️ **Jest tylko JEDEN wymiar czasu.** `first_day_on`/`last_day_on` mówią, których DÓB reguła
 * dotyczy. Dawne `effective_from`/`effective_to` („czy ten zapis bierze dziś udział w wycenie")
 * zostały wycofane: różnica między nimi jest obserwowalna dopiero przy utrwalonej transakcji
 * pamiętającej cenę z chwili zakupu, a rezerwacji jeszcze nie ma.
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

            // Stawka: kwota za osobę ŁOWIĄCĄ za dobę. Dopłata: kwota dopłaty.
            $table->decimal('amount', 8, 2);

            // ⚠️ Tylko dla `rate`. NULL znaczy BRAK CENY dla osoby towarzyszącej, a nie cenę
            // zerową — zapytanie z osobą towarzyszącą dostaje wtedy odmowę `NoCompanionPrice`.
            // Darmowa towarzysząca to `0.00` wpisane świadomie; formularz czyni to pole
            // wymaganym dla stawki, więc NULL da się osiągnąć wyłącznie zapisem poza panelem.
            $table->decimal('amount_companion', 8, 2)->nullable();

            $table->boolean('is_suspended')->default(false);

            // Jedyny wymiar czasu: których DÓB reguła dotyczy. NULL na `last_day_on` znaczy
            // „bezterminowo", co przy stawce ma dodatkowe znaczenie — taka stawka jest
            // AKTUALNYM CENNIKIEM i to ją domyka nowa stawka bezterminowa (PriceRulePeriods).
            $table->date('first_day_on')->nullable();
            $table->date('last_day_on')->nullable();

            // ⚠️ Poniższe trzy kolumny są polami DOPŁATY. Siedzą na wspólnej tabeli, bo rodzaj
            // reguły jest flagą (O3), ale przy zapisie stawki idą na NULL — nie zostawiamy
            // w bazie danych, których nikt nie interpretuje.
            //
            // `weekdays` — dni ISO 1–7 ROZPOCZĘCIA doby; NULL/pusty = każda doba.
            $table->json('weekdays')->nullable();

            // `anglers_count` — dopłata należy się wyłącznie przy DOKŁADNIE tylu łowiących.
            // ⚠️ Porównanie przez RÓWNOŚĆ z faktyczną obsadą z zapytania, nigdy z
            // `positions.max_anglers` (tamto to pojemność i kusi wyłącznie nazwą).
            // ⚠️ Obsadę liczą SAMI ŁOWIĄCY — osoba towarzysząca jej nie podnosi.
            $table->unsignedTinyInteger('anglers_count')->nullable();

            // `applies_to` — komu dopłata jest naliczana (`SurchargeAudience`).
            // ⚠️ Bez domyślnej wartości w BAZIE: domyślne `angler` żyje w formularzu, bo na
            // stawce to pole nie istnieje. To NIE jest powrót wycofanej osi `participant_role`
            // — tamta decydowała, KTÓRA STAWKA obowiązuje uczestnika, ta tylko przez ilu osób
            // mnożymy dopłatę.
            $table->string('applies_to')->nullable();

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
