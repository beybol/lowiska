<?php

namespace App\Console\Commands;

use App\Services\AdminPermissionSync;
use Illuminate\Console\Command;

/**
 * `admins:sync` — rola `super_admin` Shielda z kompletem uprawnień dla każdego konta z `is_admin`
 * (ADR-018, zadanie 025). Uruchamiana przy KAŻDYM wdrożeniu (`deploy.yml`, po migracjach, przed
 * wdrożeniem usługi), żeby nowy zasób był widoczny dla administratora od pierwszego żądania.
 *
 * ⚠️ Nieinteraktywna. Błąd kończy się kodem ≠ 0 (wyjątek), co zatrzymuje wdrożenie. Brak kont
 * z `is_admin` to OSTRZEŻENIE z kodem 0 — pierwszego administratora zakłada się `MakeAdmin`
 * dopiero po pierwszym wdrożeniu świeżego środowiska.
 */
class AdminsSyncCommand extends Command
{
    protected $signature = 'admins:sync';

    protected $description = 'Give the super admin role every Shield permission and assign it exactly to the accounts flagged is_admin';

    public function handle(AdminPermissionSync $sync): int
    {
        $report = $sync->sync();

        $this->info("Role '{$report->roleName}': {$report->permissionsBefore} → {$report->permissionsAfter} permissions.");

        foreach ($report->granted as $email) {
            $this->info("Role granted to {$email}.");
        }

        foreach ($report->revoked as $email) {
            $this->info("Role revoked from {$email} (no is_admin flag).");
        }

        if ($report->adminCount === 0) {
            $this->warn('No account has is_admin — nobody can open /admin. Create one with MakeAdmin.');
        }

        if ($report->changedNothing()) {
            $this->info('Nothing changed.');
        }

        return self::SUCCESS;
    }
}
