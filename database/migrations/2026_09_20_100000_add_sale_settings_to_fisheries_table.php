<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 015 — definicja doby wędkarskiej na poziomie łowiska.
 *
 * Kolumny godzinowe są nullable ze względu na istniejące wiersze: łowisko sprzed
 * tej migracji nie ma zdefiniowanej doby i jest wtedy niesprzedające, co jest
 * stanem zamierzonym (zadanie 015, „Rozstrzygnięcia").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->string('sale_mode', 20)->default('daily_period');
            $table->time('day_start_time')->nullable();
            $table->time('day_end_time')->nullable();
            $table->string('timezone', 64)->default('Europe/Warsaw');
        });

        // ⚠️ Wartość domyślna kolumny obsługuje wiersze zakładane później; istniejące
        // uzupełniamy jawnie, bo kryterium akceptacji mówi o KAŻDYM łowisku, a nie
        // o tych dodanych po migracji.
        DB::table('fisheries')->whereNull('timezone')->update(['timezone' => 'Europe/Warsaw']);
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn(['sale_mode', 'day_start_time', 'day_end_time', 'timezone']);
        });
    }
};
