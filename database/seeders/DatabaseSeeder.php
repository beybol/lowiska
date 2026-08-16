<?php

namespace Database\Seeders;

use App\Models\Currency;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\FishingMethod;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // ⚠️ Konto administratora ze stałym hasłem powstaje WYŁĄCZNIE poza produkcją.
        // Workflow wdrożeniowy udostępnia generyczny job artisanowy, więc `db:seed`
        // da się odpalić na produkcji jednym poleceniem — bez tej bramki powstawałby
        // wtedy natychmiastowy backdoor (audyt bezpieczeństwa, zadanie 012).
        // Konto administratora na produkcji zakłada komenda `MakeAdmin`, która losuje hasło.
        if (! app()->isProduction()) {
            User::factory()->create([
                'name' => 'Test',
                'surname' => 'Admin',
                'email' => 'test.admin@example.com',
                'password' => 'adminadmin',
                'is_admin' => true,
            ]);
        }

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

        Currency::factory()->create(['name' => 'PLN']);
        Currency::factory()->create(['name' => 'EUR']);
        Currency::factory()->create(['name' => 'USD']);
        Currency::factory()->create(['name' => 'GBP']);
    }
}
