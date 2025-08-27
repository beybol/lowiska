<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use App\Models\State;
use App\Models\Company;
use App\Models\Fish;
use App\Models\FisheryType;
use App\Models\FishingMethod;
use App\Models\Convenience;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fishery>
 */
class FisheryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company(),
            'street' => $this->faker->streetName(),
            'building_number' => $this->faker->buildingNumber(),
            'town' => $this->faker->city(),
            'state_id' => State::factory(),
            'company_id' => Company::factory(),
            'directions' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'zip_code' => $this->faker->postcode(),
            'area' => $this->faker->randomFloat(2, 1, 100),
            'avg_depth' => $this->faker->randomFloat(2, 1, 10),
            'max_depth' => $this->faker->randomFloat(2, 10, 50),
            'positions_count' => $this->faker->numberBetween(1, 10),
            'dominant_fish_id' => Fish::factory(),
            'records' => $this->faker->sentence(),
            'map_image_path' => $this->faker->imageUrl(),
            'gallery_images' => [$this->faker->imageUrl(), $this->faker->imageUrl()],
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
