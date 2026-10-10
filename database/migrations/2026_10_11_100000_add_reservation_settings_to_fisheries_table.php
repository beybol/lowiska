<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ustawienia rezerwacji łowiska — zadanie 045 (ADR-024 pkt 7, wnioski W1 z makiet etapu 3).
 *
 * - `manual_reservation_confirmation_required` — rezerwacja ręczna wymaga potwierdzenia; bez niego
 *   wygasa po `manual_reservation_hold_hours` godzinach (`reservations.confirm_by`). Domyślnie wyłączone.
 * - `public_availability_enabled` — portal pokazuje zajętość (zadanie 046). Domyślnie wyłączone:
 *   warunek uruchomienia — obsługa wpisuje każdą rezerwację telefoniczną.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->boolean('manual_reservation_confirmation_required')->default(false);
            $table->unsignedSmallInteger('manual_reservation_hold_hours')->nullable();
            $table->boolean('public_availability_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn(['manual_reservation_confirmation_required', 'manual_reservation_hold_hours', 'public_availability_enabled']);
        });
    }
};
