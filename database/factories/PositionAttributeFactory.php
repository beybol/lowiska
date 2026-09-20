<?php

namespace Database\Factories;

use App\Enums\PositionAttributeType;
use App\Models\PositionAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionAttribute>
 */
class PositionAttributeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Cecha '.$this->faker->unique()->numberBetween(1, 100000),
            'type' => PositionAttributeType::Flag,
            'unit' => null,
            'is_filterable' => false,
        ];
    }

    public function number(string $unit = 'm'): self
    {
        return $this->state(fn (): array => [
            'type' => PositionAttributeType::Number,
            'unit' => $unit,
        ]);
    }

    public function choice(): self
    {
        return $this->state(fn (): array => [
            'type' => PositionAttributeType::Choice,
            'unit' => null,
        ]);
    }
}
