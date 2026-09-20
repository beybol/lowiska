<?php

namespace Database\Factories;

use App\Models\PositionAttribute;
use App\Models\PositionAttributeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionAttributeOption>
 */
class PositionAttributeOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position_attribute_id' => PositionAttribute::factory()->choice(),
            'name' => 'Opcja '.$this->faker->unique()->numberBetween(1, 100000),
            'sort_order' => 0,
        ];
    }
}
