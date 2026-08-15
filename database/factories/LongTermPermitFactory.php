<?php

namespace Database\Factories;

use App\Models\Fishery;
use App\Models\LongTermPermit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LongTermPermit>
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
