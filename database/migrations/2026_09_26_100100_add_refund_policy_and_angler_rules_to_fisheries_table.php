<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polityka zwrotu i wymagania wobec wędkarza jako dane łowiska — zadanie 021.
 *
 * ⚠️ Progi zwrotu to kolumna JSON (lista `{days, percent}`), wzorem `weekend_days`: nikt nie
 * odpytuje ich SQL-em, a zmiany loguje istniejący dziennik łowiska. `null` = „polityka
 * nieustawiona", czyli ani 0%, ani 100%.
 *
 * ⚠️ Każdy parametr ma stan „nie podano" (`null`) i istniejące łowiska startują właśnie z nim —
 * niewypełnione łowisko nie może ogłaszać „karta niewymagana" ani „można zabrać rybę".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->json('refund_policy')->nullable();
            $table->boolean('fishing_license_required')->nullable();
            $table->unsignedTinyInteger('rods_included')->nullable();
            $table->boolean('no_kill')->nullable();
            $table->boolean('campfires_banned')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn(['refund_policy', 'fishing_license_required', 'rods_included', 'no_kill', 'campfires_banned']);
        });
    }
};
