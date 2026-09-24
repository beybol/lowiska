<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminPermissionSync;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'MakeAdmin {name} {surname} {email} {--password= : Hasło konta; pominięte = adres e-mail (patrz komentarz przy tworzeniu konta)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user or promote an existing user to admin, ensuring the super admin role has the full set of Shield permissions';

    /**
     * Execute the console command.
     */
    public function handle(AdminPermissionSync $sync)
    {
        $name = $this->argument('name');
        $surname = $this->argument('surname');
        $email = $this->argument('email');

        // ⚠️ Komenda ZAKŁADA albo PROMUJE konto (`is_admin`), a rolę i uprawnienia deleguje do
        // `AdminPermissionSync` — tej samej logiki co `admins:sync` przy każdym wdrożeniu
        // (ADR-018). Flaga jest źródłem prawdy, rola jej pochodną, więc flagę ustawia się
        // PRZED synchronizacją.
        $user = User::where('email', $email)->first();
        $isNewUser = ! $user;

        if ($user) {
            $user->is_admin = true;
            $user->save();
        } else {
            // ⚠️ Hasło domyślnie RÓWNE adresowi e-mail — to jest świadome rozstrzygnięcie
            // autora, nie przeoczenie (zadanie 012, §21). Audyt bezpieczeństwa zgłosił to
            // jako HIGH i słusznie: adres administratora jest jawny w workflow wdrożeniowym.
            // Utrzymane, bo na tym etapie nie ma modelu zagrożeń — projekt nie jest
            // produkcyjny, staging stoi za osobnym hasłem, a losowe hasło wypisywane raz
            // w logach `gcloud run jobs execute` grozi utratą dostępu do konta.
            //
            // ⚠️ WARUNEK POWROTU: pierwsze wdrożenie produkcyjne. Wtedy hasło musi być
            // losowe albo podane, a docelowo dochodzi wymuszone 2FA dla `is_admin`
            // i wymuszona zmiana hasła przy pierwszym logowaniu — patrz `TODO.md`.
            // `--password` działa już dziś i jest właściwą ścieżką na produkcji.
            $password = $this->option('password') ?: $email;

            User::create([
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
                'password' => $password,
                'is_admin' => true,
            ]);
        }

        $report = $sync->sync();
        $permissionCount = $report->permissionsAfter;

        if ($isNewUser) {
            $this->info("Admin created with {$permissionCount} permissions assigned to role '{$report->roleName}'.");
        } elseif ($report->roleWasComplete()) {
            $this->info("User promoted to admin. Role '{$report->roleName}' already had the full set of {$permissionCount} permissions — nothing changed.");
        } else {
            $this->info("User promoted to admin. Role '{$report->roleName}' permissions completed: {$report->permissionsBefore} → {$permissionCount}.");
        }
    }
}
