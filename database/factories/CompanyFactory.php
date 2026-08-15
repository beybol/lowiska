<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
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
