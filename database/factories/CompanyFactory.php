<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use App\Models\State;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
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
            'is_verified' => false,
            'name' => $this->faker->company(),
            'tin' => $this->faker->numerify('#########'),
            'renae' => $this->faker->numerify('#########'),
            'street' => $this->faker->streetName(),
            'house_number' => $this->faker->buildingNumber(),
            'flat_number' => $this->faker->optional()->buildingNumber(),
            'postal_code' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'state_id' => State::factory(),
            'cso_response' => 'test',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
