<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Fishery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'type' => DocumentType::Terms,
            'title' => 'Regulamin '.$this->faker->unique()->numberBetween(1, 100000),
            'effective_from' => '2026-01-01',
            'content' => '<p>'.$this->faker->sentence().'</p>',
            'required_at_purchase' => true,
            'required_at_registration' => false,
        ];
    }
}
