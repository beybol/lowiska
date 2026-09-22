<?php

namespace Database\Factories;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
use App\Models\Fishery;
use App\Models\PriceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    /**
     * Domyślnie **stawka bazowa łowiska**: reguła `rate` bez żadnych dat, z darmową osobą
     * towarzyszącą. Oba łowiska klienta obchodzą się jedną taką regułą plus jedną dopłatą.
     *
     * ⚠️ `amount_companion` domyślnie `0.00`, a nie `null`: `null` znaczy BRAK CENY, czyli
     * odmowę sprzedaży komuś z osobą towarzyszącą. Fabryka ma dawać cennik, który działa.
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
            'amount_companion' => 0.00,
            'is_suspended' => false,
            'first_day_on' => null,
            'last_day_on' => null,
            'weekdays' => null,
            'anglers_count' => null,
            'applies_to' => null,
        ];
    }

    /**
     * Dopłata — domyślnie „dla łowiącego", jak w obu realnych cennikach.
     */
    public function surcharge(float $amount, ?string $label = null): self
    {
        return $this->state(fn (): array => [
            'kind' => PriceRuleKind::Surcharge->value,
            'amount' => $amount,
            'label' => $label,
            'amount_companion' => null,
            'applies_to' => SurchargeAudience::Angler->value,
        ]);
    }

    public function amount(float $amount): self
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }

    /**
     * Kwota za osobę towarzyszącą; `null` znaczy BRAK CENY, nie zero.
     */
    public function companionAmount(?float $amount): self
    {
        return $this->state(fn (): array => ['amount_companion' => $amount]);
    }

    public function chargedTo(SurchargeAudience $audience): self
    {
        return $this->state(fn (): array => ['applies_to' => $audience->value]);
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

    public function suspended(): self
    {
        return $this->state(fn (): array => ['is_suspended' => true]);
    }
}
