<?php

namespace Database\Factories;

use App\Enums\PositionStatus;
use App\Models\Fishery;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // ⚠️ Etykieta MUSI być unikalna w obrębie łowiska — od zadania 014
            // pilnuje tego indeks unikalny `(fishery_id, name)`. `faker->word()`
            // powtarza się na tyle często, że dwa stanowiska jednego łowiska
            // wywracały pakiet losowo, co wygląda na awarię środowiska.
            'name' => 'Stanowisko '.$this->faker->unique()->numberBetween(1, 1000000),
            'description' => $this->faker->sentence(),
            'fishery_id' => Fishery::factory(),
            'max_anglers' => 2,
            'max_people' => 4,
            'status' => PositionStatus::Withdrawn,
        ];
    }
}
