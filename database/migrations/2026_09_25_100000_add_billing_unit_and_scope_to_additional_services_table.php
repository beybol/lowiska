<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usługi dodatkowe: jednostka rozliczenia, zasięg i wymagane cechy stanowiska (zadanie 020).
 *
 * ⚠️ Istniejące usługi dostają jednostkę „za dobę" i zasięg „wybrane stanowiska" — aplikacja nie
 * działa produkcyjnie, więc wartości domyślne nie niosą ryzyka. Te bez przypięć pokazują potem
 * ostrzeżenie „dostępna nigdzie", zamiast po cichu otworzyć się na całym łowisku.
 *
 * ⚠️ `available_count = 0` znaczyło dotąd „bez limitu" (podpowiedź formularza). Od tego zadania brak
 * limitu to `null`, a liczba oznacza egzemplarze dostępne w każdej dobie — stąd zamiana zera.
 *
 * ⚠️ `additional_service_long_term_permit` celowo nietknięte (K18, G13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_services', function (Blueprint $table) {
            $table->string('billing_unit')->default('per_night')->after('price');
            $table->string('scope')->default('selected_positions')->after('billing_unit');
        });

        DB::table('additional_services')->where('available_count', 0)->update(['available_count' => null]);

        // ⚠️ Jawne, krótkie nazwy kluczy — nazwa wygenerowana z nazwy tej tabeli przekracza limit
        // 64 znaków MySQL-a (ten sam powód co `as_pos_*` w tabelach pośrednich z 2025 r.).
        Schema::create('additional_service_required_attribute', function (Blueprint $table) {
            $table->unsignedBigInteger('additional_service_id');
            $table->unsignedBigInteger('position_attribute_id');
            $table->foreign('additional_service_id', 'as_ra_as_id_foreign')
                ->references('id')
                ->on('additional_services')
                ->cascadeOnDelete();
            $table->foreign('position_attribute_id', 'as_ra_pa_id_foreign')
                ->references('id')
                ->on('position_attributes')
                ->cascadeOnDelete();
            $table->primary(['additional_service_id', 'position_attribute_id'], 'as_ra_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_service_required_attribute');

        Schema::table('additional_services', function (Blueprint $table) {
            $table->dropColumn(['billing_unit', 'scope']);
        });
    }
};
