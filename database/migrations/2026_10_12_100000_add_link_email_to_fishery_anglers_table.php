<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adres, na który wysłano prośbę o powiązanie (zadanie 047, D2).
 *
 * ⚠️ Token powiązania jest ważny wyłącznie dla adresu z chwili wysyłki. Gdy operator poprawi adres
 * (literówka), stary link z maila wysłanego na pomylony adres przestaje działać — bez tego osoba,
 * która dostała mail przez pomyłkę, dowiązałaby rezerwację do konta właściciela poprawionego adresu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fishery_anglers', function (Blueprint $table) {
            $table->string('link_email')->nullable()->after('link_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('fishery_anglers', function (Blueprint $table) {
            $table->dropColumn('link_email');
        });
    }
};
