<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 018 — procentowa obniżka ceny za zakup w oknie przedsprzedaży.
 *
 * ⚠️ Piąta kolumna bloku przedsprzedaży, obok czterech z zadania 017 — i renderuje się na
 * ekranie „Sprzedaż i sezony", NIE na „Cenniku". Wszystkie pięć opisuje **tę samą ofertę tego
 * samego sezonu**; rozdzielenie ich zmuszałoby operatora do składania jednej przedsprzedaży
 * z dwóch formularzy i pozwalałoby ustawić obniżkę dla okresu, który przedsprzedaży nie ma.
 *
 * ⚠️ To NIE jest dopłata procentowa zakazana w M3 („procent od czego", zależność od kolejności
 * naliczania). To modyfikator całego zakupu w oknie, z jawnie wskazaną podstawą (suma pozycji
 * doby) i jednym miejscem naliczania. Zakaz dopłat procentowych **zostaje w mocy** dla
 * `price_rules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_periods', function (Blueprint $table) {
            $table->decimal('presale_discount_percent', 5, 2)
                ->nullable()
                ->after('presale_whole_terms_bypass_min_nights');
        });
    }

    public function down(): void
    {
        Schema::table('sale_periods', function (Blueprint $table) {
            $table->dropColumn('presale_discount_percent');
        });
    }
};
