<?php

use App\Services\PositionLabelDuplicateGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 014 — pojemność stanowiska i stan zamiast binarnego `is_active`.
 *
 * ⚠️ Indeks unikalny `(fishery_id, name)` obejmuje także wiersze usunięte miękko,
 * dzięki czemu etykieta wycofanego stanowiska nie wraca do obiegu. To jest cel,
 * nie efekt uboczny — dlatego indeks NIE bierze pod uwagę `deleted_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ Wykrycie duplikatów mieszka w klasie w `app/`, nie w ciele migracji —
        // logiki w migracji nie da się przetestować, bo `RefreshDatabase` uruchamia
        // migracje w `setUp()` (`docs/conventions/dziennik-zmian.md` §2).
        (new PositionLabelDuplicateGuard)->assertNone();

        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_anglers')->nullable();
            $table->unsignedTinyInteger('max_people')->nullable();
            $table->string('status', 20)->default('available');
        });

        // Przeniesienie danych PRZED usunięciem kolumny — inaczej informacja
        // o wycofanych stanowiskach przepada bezpowrotnie.
        DB::table('positions')->where('is_active', 1)->update(['status' => 'available']);
        DB::table('positions')->where('is_active', 0)->update(['status' => 'withdrawn']);

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('is_active');
            $table->unique(['fishery_id', 'name']);
        });
    }

    public function down(): void
    {
        // ⚠️ KOLEJNOŚĆ JEST ISTOTNA i nieoczywista. Indeks unikalny `(fishery_id, name)`
        // zaczyna się od `fishery_id`, więc po jego nałożeniu InnoDB uznaje go za
        // indeks podpierający klucz obcy `positions_fishery_id_foreign` i zostaje on
        // JEDYNYM takim indeksem. Próba zdjęcia go wprost kończy się błędem
        // „Cannot drop index …: needed in a foreign key constraint" — a że `down()`
        // zdążył wcześniej dodać kolumnę, tabela zostaje w stanie pośrednim.
        // Dlatego najpierw oddajemy kluczowi obcemu własny indeks, dopiero potem
        // zdejmujemy unikalny.
        // ⚠️ Warunkowo: po pełnym cyklu w dół i w górę indeks już istnieje (up() go nie
        // zdejmuje), więc bezwarunkowe dodanie wywraca drugi rollback na „Duplicate key
        // name". Migracja odwracalna musi znieść OBA stany wyjściowe.
        if (! Schema::hasIndex('positions', 'positions_fishery_id_foreign')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->index('fishery_id', 'positions_fishery_id_foreign');
            });
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique('positions_fishery_id_name_unique');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('is_active')->default(false);
        });

        DB::table('positions')->where('status', 'available')->update(['is_active' => 1]);

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['max_anglers', 'max_people', 'status']);
        });
    }
};
