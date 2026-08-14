<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Fishery;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LongTermPermit>
 */
class LongTermPermitFactory extends Factory
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
            'description' => $this->faker->sentence(),
            'valid_from' => $this->faker->date(),
            'valid_to' => $this->faker->date(),
            'fishery_id' => Fishery::factory(),
            'price' => $this->faker->randomFloat(2, 0, 1000),
            'sales_limit' => $this->faker->numberBetween(1, 100),
        ];
    }
}
