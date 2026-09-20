<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tożsamość dostawcy logowania społecznościowego.
 *
 * ⚠️ Powstaje, bo `SocialAuthController` wiązał konto po SAMYM adresie e-mail:
 * `firstOrCreate(['email' => …])` na adresie równym adresowi istniejącego konta
 * hasłowego zwracał to konto i logował na nie — w tym na konto `is_admin`
 * (security-review, 2026-09-20). Bez zapisanej tożsamości dostawcy nie da się
 * odróżnić „to jest ten sam człowiek" od „ktoś podał ten sam adres".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('password');
            $table->string('provider_id', 191)->nullable()->after('provider');

            // Jedna tożsamość dostawcy = jedno konto. Częściowa para NULL nie łamie
            // unikalności w MySQL-u, więc konta hasłowe pozostają nietknięte.
            $table->unique(['provider', 'provider_id'], 'users_provider_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_provider_identity_unique');
            $table->dropColumn(['provider', 'provider_id']);
        });
    }
};
