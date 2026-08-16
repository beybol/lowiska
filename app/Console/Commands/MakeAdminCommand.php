<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
    public function handle()
    {
        $name = $this->argument('name');
        $surname = $this->argument('surname');
        $email = $this->argument('email');

        // shield:generate never runs on its own (no seeder, no deploy step), so a
        // freshly-provisioned system has zero permissions in the database — syncing
        // an empty/partial Permission::all() would leave the role just as blind as
        // it started. Generate first, then sync.
        //
        // --silent replaced --minimal in Shield 4 (zadanie 009); --option=permissions
        // still keeps the generator off app/Policies/, which this project maintains
        // by hand (see config/filament-shield.php, policies.generate => false).
        foreach (['admin', 'owner'] as $panel) {
            Artisan::call('shield:generate', [
                '--panel' => $panel,
                '--option' => 'permissions',
                '--all' => true,
                '--silent' => true,
            ]);
        }

        $superAdminRoleName = config('filament-shield.super_admin.name');
        $superAdminRole = Role::firstOrCreate(['name' => $superAdminRoleName]);

        $allPermissions = Permission::all();
        $existingPermissionIds = $superAdminRole->permissions()->pluck('id')->sort()->values();
        $allPermissionIds = $allPermissions->pluck('id')->sort()->values();
        $roleWasComplete = $existingPermissionIds->all() === $allPermissionIds->all();

        if (! $roleWasComplete) {
            $superAdminRole->syncPermissions($allPermissions);
        }

        $user = User::where('email', $email)->first();
        $isNewUser = ! $user;

        if ($user) {
            $user->is_admin = true;
            $user->save();

            if (! $user->hasRole($superAdminRole)) {
                $user->assignRole($superAdminRole);
            }
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

            $user = User::create([
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
                'password' => $password,
                'is_admin' => true,
            ]);
            $user->assignRole($superAdminRole);
        }

        $permissionCount = $allPermissions->count();

        if ($isNewUser) {
            $this->info("Admin created with {$permissionCount} permissions assigned to role '{$superAdminRoleName}'.");
        } elseif ($roleWasComplete) {
            $this->info("User promoted to admin. Role '{$superAdminRoleName}' already had the full set of {$permissionCount} permissions — nothing changed.");
        } else {
            $this->info("User promoted to admin. Role '{$superAdminRoleName}' permissions completed: {$existingPermissionIds->count()} → {$permissionCount}.");
        }
    }
}
