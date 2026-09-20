<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionAttributeValue>
 */
class PositionAttributeValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position_id' => Position::factory(),
            'position_attribute_id' => PositionAttribute::factory(),
            'value_flag' => true,
            'value_number' => null,
            'position_attribute_option_id' => null,
        ];
    }
}
