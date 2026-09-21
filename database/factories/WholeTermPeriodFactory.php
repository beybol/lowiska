<?php

namespace Database\Factories;

use App\Models\Fishery;
use App\Models\WholeTermPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WholeTermPeriod>
 */
class WholeTermPeriodFactory extends Factory
{
    /**
     * ⚠️ Domyślnie TRZY doby (30.04, 01.05, 02.05), nie dwie — `last_day_on` wskazuje
     * dobę objętą, nie granicę okna. Wartości mieszczą się w domyślnym okresie
     * sprzedaży z `SalePeriodFactory` (01.02–30.09.2026), żeby walidacja mieszczenia
     * się w sezonie przechodziła bez dodatkowej konfiguracji w teście.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fishery_id' => Fishery::factory(),
            'name' => 'Majowka',
            'first_day_on' => '2026-04-30',
            'last_day_on' => '2026-05-02',
        ];
    }

    /**
     * Święto o zadanej liczbie dób, licząc od dnia rozpoczęcia pierwszej doby.
     */
    public function nights(string $firstDayOn, int $nights): self
    {
        return $this->state(fn (): array => [
            'first_day_on' => $firstDayOn,
            'last_day_on' => CarbonImmutable::parse($firstDayOn)
                ->addDays($nights - 1)
                ->toDateString(),
        ]);
    }
}
