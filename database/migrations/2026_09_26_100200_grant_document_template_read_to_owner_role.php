<?php

use App\Services\OwnerRoleProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Właściciel czyta szablony dokumentów (lista przy nowej wersji i podgląd) — zadanie 021.
 *
 * ⚠️ `OwnerRoleProvisioner::provisionRole()` ustawia uprawnienia wyłącznie roli, której jeszcze
 * nie ma (`autoryzacja.md` §1). Rola `owner` istniejąca w bazie nie dostałaby więc nowych
 * uprawnień bez tej migracji — dokłada je, niczego innego w roli nie ruszając.
 */
return new class extends Migration
{
    public function up(): void
    {
        OwnerRoleProvisioner::grantDocumentTemplateReading();
    }

    public function down(): void
    {
        //
    }
};
