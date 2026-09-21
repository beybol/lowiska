<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 017 — święta sprzedawane wyłącznie w całości (spoiwo jednorazowe, datowane).
 *
 * ⚠️ **Kolumny nazywają się inaczej niż w `sale_periods`, bo znaczą co innego — i to
 * jest cały powód tej nazwy.** W okresie sprzedaży `ends_on` to ostatni dzień
 * kalendarzowy okna, a doba musi się w oknie zawrzeć w całości, więc ostatnia
 * sprzedawalna doba zaczyna się DZIEŃ WCZEŚNIEJ. Tutaj `last_day_on` to dzień
 * rozpoczęcia ostatniej OBJĘTEJ doby. Ta sama para dat daje więc dwie różne liczby dób
 * (30.04–02.05: okres → 2 doby, święto → 3 doby).
 * ⚠️ Nie kopiuj logiki granic z `SalePeriod` — różne nazwy są zabezpieczeniem, nie
 * kosmetyką (`docs/conventions/dostepnosc.md` §4).
 *
 * ⚠️ **Brak indeksu unikalnego i brak reguły nienachodzenia.** Święta mogą na siebie
 * zachodzić i mogą zachodzić na weekend — nachodzące spoiwa ZLEWAJĄ się w jeden pakiet,
 * więc nachodzenie jest stanem poprawnym, nie błędem zapisu.
 *
 * `fishery_id` wymagany z kaskadą, jak `sale_periods`, `position_groups`
 * i `availability_blocks`: święto nie ma wartości historycznej ani ścieżki dostępu
 * poza łowiskiem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whole_term_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->date('first_day_on');
            $table->date('last_day_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fishery_id', 'first_day_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whole_term_periods');
    }
};
