<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Dolnośląskie',
                'Kujawsko-pomorskie',
                'Lubelskie',
                'Lubuskie',
                'Łódzkie',
                'Małopolskie',
                'Mazowieckie',
                'Opolskie',
                'Podkarpackie',
                'Podlaskie',
                'Pomorskie',
                'Śląskie',
                'Świętokrzyskie',
                'Warmińsko-mazurskie',
                'Wielkopolskie',
                'Zachodniopomorskie',
            ]),
            'country_id' => Country::poland()->value('id')
                ?? Country::factory()->create(['country_name' => 'Poland'])->id,
        ];
    }
}
