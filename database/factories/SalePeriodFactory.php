<?php

namespace Database\Factories;

use App\Models\Fishery;
use App\Models\SalePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePeriod>
 */
class SalePeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'name' => $this->faker->word(),
            'starts_on' => '2026-02-01',
            'ends_on' => '2026-09-30',
        ];
    }
}
