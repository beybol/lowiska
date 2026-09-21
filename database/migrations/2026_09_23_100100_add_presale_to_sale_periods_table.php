<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 017 — przedsprzedaż jako WŁAŚCIWOŚĆ OKRESU SPRZEDAŻY.
 *
 * ⚠️ Nie ma tabeli `presale_windows` ani kolumn `covers_from`/`covers_to`: zakres dób
 * objętych przedsprzedażą jest z definicji zakresem okresu, więc nie da się
 * skonfigurować okna obejmującego doby poza sezonem. Wariant z osobną tabelą został
 * odrzucony przy przeglądzie zadania (rozstrzygnięcie 2).
 *
 * ⚠️ `presale_whole_terms_bypass_min_nights` jest domyślnie WŁĄCZONA (P15/K4): pobyt
 * obejmujący pakiet ze świętem nie podlega minimum okna. Domyślna wartość jest
 * ustawiona po stronie MySQL-a ORAZ w `$attributes` modelu — `create()` nie czyta
 * z powrotem kolumn wypełnionych domyślną wartością bazy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_periods', function (Blueprint $table) {
            $table->date('presale_opens_on')->nullable()->after('ends_on');
            $table->date('presale_closes_on')->nullable()->after('presale_opens_on');
            $table->unsignedSmallInteger('presale_min_nights')->nullable()->after('presale_closes_on');
            $table->boolean('presale_whole_terms_bypass_min_nights')
                ->default(true)
                ->after('presale_min_nights');
        });
    }

    public function down(): void
    {
        Schema::table('sale_periods', function (Blueprint $table) {
            $table->dropColumn([
                'presale_opens_on',
                'presale_closes_on',
                'presale_min_nights',
                'presale_whole_terms_bypass_min_nights',
            ]);
        });
    }
};
