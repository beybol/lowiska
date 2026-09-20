<?php

namespace Database\Factories;

use App\Enums\BlockEffect;
use App\Enums\SelectionKind;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\PositionAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityBlock>
 */
class AvailabilityBlockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'effect' => BlockEffect::SaleBlocked,
            'position_attribute_id' => null,
            'starts_on' => '2026-06-10',
            'ends_on' => '2026-06-20',
            'reason' => $this->faker->sentence(),
            'reason_visible' => true,
            'selection_kind' => SelectionKind::Manual,
            'selection_label' => null,
        ];
    }

    public function suspending(PositionAttribute $attribute): self
    {
        return $this->state(fn (): array => [
            'effect' => BlockEffect::AttributeSuspended,
            'position_attribute_id' => $attribute->id,
        ]);
    }

    public function openEnded(): self
    {
        return $this->state(fn (): array => ['ends_on' => null]);
    }
}
