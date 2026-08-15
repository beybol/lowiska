<?php

namespace Database\Factories;

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
            'is_active' => false,
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'fishery_id' => Fishery::factory(),
        ];
    }
}
