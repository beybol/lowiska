<?php

namespace Database\Factories;

use App\Models\Fishery;
use App\Models\PositionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionGroup>
 */
class PositionGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'name' => 'Grupa '.$this->faker->unique()->numberBetween(1, 100000),
            'description' => $this->faker->sentence(),
        ];
    }
}
