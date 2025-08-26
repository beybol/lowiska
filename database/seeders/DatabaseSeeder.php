<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\State;
use App\Models\FishingMethod;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test',
            'surname' => 'Admin',
            'email' => 'test.admin@example.com',
            'password' => 'adminadmin',
            'is_admin' => true,
            'is_owner' => true,
        ]);

        $this->call(OwnerRoleSeeder::class);

        State::factory()->create(['name' => 'Greater Poland']);
        State::factory()->create(['name' => 'Holy Cross']);
        State::factory()->create(['name' => 'Lesser Poland']);
        State::factory()->create(['name' => 'Lower Silesian']);
        State::factory()->create(['name' => 'Kuyavian-Pomeranian']);
        State::factory()->create(['name' => 'Lodz']);
        State::factory()->create(['name' => 'Lublin']);
        State::factory()->create(['name' => 'Lubusz']);
        State::factory()->create(['name' => 'Masovian']);
        State::factory()->create(['name' => 'Opole']);
        State::factory()->create(['name' => 'Podlaskie']);
        State::factory()->create(['name' => 'Pomeranian']);
        State::factory()->create(['name' => 'Silesian']);
        State::factory()->create(['name' => 'Subcarpathian']);
        State::factory()->create(['name' => 'Warmian-Masurian']);
        State::factory()->create(['name' => 'West Pomeranian']);

        FishingMethod::factory()->create(['name' => 'Ground']);
        FishingMethod::factory()->create(['name' => 'Float']);
        FishingMethod::factory()->create(['name' => 'Boat']);
    }
}
