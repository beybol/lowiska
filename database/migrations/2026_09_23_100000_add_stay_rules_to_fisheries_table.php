<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 017 — reguły pobytu na łowisku: długość, weekend i horyzont sprzedaży.
 *
 * ⚠️ `weekend_days` jest KOLUMNĄ JSON, nie tabelą — zbiór co najwyżej siedmiu małych
 * liczb zapisywany w całości jednym polem formularza. Osobny model, relacja i fabryka
 * byłyby abstrakcją bez pokrycia (`CLAUDE.md`: nie projektujemy pod hipotetyczne
 * wymagania). Moment na tabelę przyjdzie, gdy pojawi się łowisko z drugim cyklicznym
 * pakietem albo weekendem różnym per sezon — migracja siedmiu liczb jest trywialna.
 *
 * ⚠️ Wszystkie cztery kolumny są `nullable`, bo **brak reguły pobytu znaczy brak
 * ograniczenia**. Jest to świadoma asymetria wobec okresów sprzedaży, gdzie brak wpisu
 * jest odmową (zadanie 015) — patrz `docs/conventions/dostepnosc.md` §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_nights')->nullable()->after('timezone');
            $table->unsignedSmallInteger('max_nights')->nullable()->after('min_nights');
            $table->json('weekend_days')->nullable()->after('max_nights');
            $table->unsignedSmallInteger('sale_horizon_days')->nullable()->after('weekend_days');
        });
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn([
                'min_nights',
                'max_nights',
                'weekend_days',
                'sale_horizon_days',
            ]);
        });
    }
};
