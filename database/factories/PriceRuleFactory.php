<?php

namespace Database\Factories;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Models\Fishery;
use App\Models\PriceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    /**
     * Domyślnie **stawka bazowa łowiska**: reguła `rate` bez ani jednego warunku.
     * Oba łowiska klienta obchodzą się jedną taką regułą plus jedną dopłatą.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'kind' => PriceRuleKind::Rate->value,
            'label' => null,
            'amount' => 70.00,
            'priority' => 0,
            'is_suspended' => false,
            'effective_from' => null,
            'effective_to' => null,
            'weekdays' => null,
            'first_day_on' => null,
            'last_day_on' => null,
            'anglers_count' => null,
            'participant_role' => null,
        ];
    }

    public function surcharge(float $amount, ?string $label = null): self
    {
        return $this->state(fn (): array => [
            'kind' => PriceRuleKind::Surcharge->value,
            'amount' => $amount,
            'label' => $label,
        ]);
    }

    public function amount(float $amount): self
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }

    public function priority(int $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    /**
     * @param  array<int, int>  $weekdays  dni ISO-8601 rozpoczęcia doby
     */
    public function onWeekdays(array $weekdays): self
    {
        return $this->state(fn (): array => ['weekdays' => $weekdays]);
    }

    public function between(?string $firstDayOn, ?string $lastDayOn): self
    {
        return $this->state(fn (): array => [
            'first_day_on' => $firstDayOn,
            'last_day_on' => $lastDayOn,
        ]);
    }

    public function forAnglers(int $count): self
    {
        return $this->state(fn (): array => ['anglers_count' => $count]);
    }

    public function forRole(ParticipantRole $role): self
    {
        return $this->state(fn (): array => ['participant_role' => $role->value]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['is_suspended' => true]);
    }

    public function effective(?string $from, ?string $to = null): self
    {
        return $this->state(fn (): array => [
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }
}
