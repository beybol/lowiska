<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Spatie\Permission\Models\Role;

class MakeAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'MakeAdmin {name} {surname} {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user or promote an existing user to admin';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $surname = $this->argument('surname');
        $email = $this->argument('email');
        $superAdminRole = Role::firstOrCreate([
            'name' => config('filament-shield.super_admin.name'),
        ]);
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->is_admin = true;
            $user->save();

            if (!$user->hasRole($superAdminRole)) {
                $user->assignRole($superAdminRole);
            }

            $this->info('User promoted to admin.');
        } else {
            $user = User::create([
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
                'password' => $email,
                'is_admin' => true,
            ]);
            $user->assignRole($superAdminRole);
            $this->info('Admin created.');
        }
    }
}
