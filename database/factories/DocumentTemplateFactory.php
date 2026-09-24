<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DocumentType::Terms,
            'name' => 'Szablon '.$this->faker->unique()->numberBetween(1, 100000),
            'content' => '<p>'.$this->faker->sentence().'</p>',
        ];
    }
}
