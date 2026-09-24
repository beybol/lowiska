<?php

use App\Services\HasPasswordBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Czy użytkownik ZNA hasło do konta (zadanie 028).
 *
 * ⚠️ `users.password` jest NOT NULL, więc konto założone przez dostawcę (Google) dostaje hasło
 * losowe — nieodróżnialne od prawdziwego. Ta kolumna mówi, czy hasło ustawił człowiek; lista kont
 * w `/admin` pokazuje na jej podstawie „Google" albo „Google + hasło".
 *
 * Dane: do tej pory jedyną drogą do powiązania z dostawcą było ZAŁOŻENIE konta przez dostawcę
 * (dowiązanie do konta hasłowego nie istniało), więc każde konto z dostawcą ma hasło losowe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_password')->default(true)->after('password');
        });

        HasPasswordBackfill::run();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_password');
        });
    }
};
