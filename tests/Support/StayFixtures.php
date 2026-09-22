<?php

namespace Tests\Support;

use App\Enums\BlockEffect;
use App\Enums\PositionStatus;
use App\Enums\SelectionKind;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use App\Models\User;
use App\Models\WholeTermPeriod;
use App\Services\OwnerRoleProvisioner;
use App\Services\StayOffer;
use App\Services\StayPricing;
use App\Services\StaySellability;

/**
 * Wspólne przygotowanie danych dla testów reguł pobytu (zadanie 017).
 *
 * ⚠️ Klasa, a nie funkcje pomocnicze w pliku testowym, i to jest świadome: funkcje
 * globalne Pesta są dostępne między plikami tylko wtedy, gdy plik je definiujący
 * został wczytany, więc `--filter` na jednym pliku potrafi je zgubić. Klasa ładuje
 * się autoloaderem niezależnie od filtra.
 *
 * ⚠️ Nie trafia do `tests/Pest.php` ani do `tests/TestCase.php` **z rozmysłem** — oba
 * te pliki są na liście wyzwalaczy T3 w `CLAUDE.md`, więc dopisanie tam czegokolwiek
 * podniosłoby zakres testów całego zadania.
 *
 * Kalendarz odniesienia (2026): 29.04 śr · 30.04 czw · 01.05 pt · 02.05 sob ·
 * 03.05 nd · 04.05 pon.
 */
final class StayFixtures
{
    /**
     * Łowisko z dobą 15:00 → 15:00, sprzedające przez cały 2026 rok, i jedno
     * stanowisko w sprzedaży.
     *
     * @param  array<string, mixed>  $attributes  nadpisania na łowisku (reguły pobytu)
     * @return array{0: Fishery, 1: Position, 2: User}
     */
    public static function fisheryWithPosition(array $attributes = []): array
    {
        $owner = User::factory()->create(['name' => 'Wlasciciel Testowy']);
        OwnerRoleProvisioner::addOwnerRole($owner);

        $fishery = Fishery::factory()->forUser($owner)->create(array_merge([
            'day_start_time' => '15:00:00',
            'day_end_time' => '15:00:00',
            'timezone' => 'Europe/Warsaw',
        ], $attributes));

        SalePeriod::factory()->create([
            'fishery_id' => $fishery->id,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);

        $position = Position::factory()->create([
            'fishery_id' => $fishery->id,
            'status' => PositionStatus::Available,
        ]);

        return [$fishery, $position, $owner];
    }

    /**
     * Usługa reguł pobytu na świeżo wczytanym stanowisku.
     *
     * ⚠️ `fresh()` nie jest ozdobą: instancja usługi pamięta odczyty (święta, okresy,
     * dostępność dób) na czas jednego pytania, więc test zmieniający dane musi zażądać
     * nowej instancji — i o tym właśnie mówi docblock `StaySellability`.
     */
    public static function stay(Position $position): StaySellability
    {
        return new StaySellability($position->fresh());
    }

    /**
     * Święto sprzedawane w całości o zadanej liczbie dób.
     *
     * ⚠️ `$nights` to liczba DÓB, a `last_day_on` wskazuje dzień rozpoczęcia ostatniej
     * z nich — nie dzień wyjazdu i nie granicę okna jak `sale_periods.ends_on`.
     */
    public static function wholeTerm(Fishery $fishery, string $firstDayOn, int $nights): WholeTermPeriod
    {
        return WholeTermPeriod::factory()
            ->nights($firstDayOn, $nights)
            ->create(['fishery_id' => $fishery->id]);
    }

    /**
     * Stawka bazowa łowiska — reguła `rate` bez ani jednego warunku (zadanie 018).
     *
     * ⚠️ Warunki dokłada się stanami fabryki (`onWeekdays()`, `forAnglers()`, `forRole()`,
     * `between()`), bo pusta oś znaczy „bez warunku", a nie „warunek fałszywy".
     */
    public static function rate(Fishery $fishery, float $amount = 70.00, array $state = []): PriceRule
    {
        return PriceRule::factory()
            ->amount($amount)
            ->create(array_merge(['fishery_id' => $fishery->id], $state));
    }

    public static function surcharge(
        Fishery $fishery,
        float $amount = 20.00,
        ?string $label = null,
        array $state = [],
    ): PriceRule {
        return PriceRule::factory()
            ->surcharge($amount, $label)
            ->create(array_merge(['fishery_id' => $fishery->id], $state));
    }

    /**
     * Wycena na świeżo wczytanym stanowisku.
     *
     * ⚠️ `fresh()` z tego samego powodu co przy `stay()`: instancja pamięta cennik na czas
     * jednego pytania, więc test zmieniający reguły musi zażądać nowej.
     */
    public static function pricing(Position $position): StayPricing
    {
        return new StayPricing($position->fresh());
    }

    /** Warstwa oferty na świeżo wczytanym stanowisku. */
    public static function offer(Position $position): StayOffer
    {
        return new StayOffer($position->fresh());
    }

    /**
     * Blokada sprzedaży na stanowisku, ze zmaterializowanym zbiorem — tak jak zapisuje
     * ją panel (zadanie 016).
     */
    public static function blockSale(
        Fishery $fishery,
        Position $position,
        string $startsOn,
        ?string $endsOn = null,
    ): AvailabilityBlock {
        $block = AvailabilityBlock::factory()->create([
            'fishery_id' => $fishery->id,
            'effect' => BlockEffect::SaleBlocked->value,
            'position_attribute_id' => null,
            'selection_kind' => SelectionKind::Manual->value,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);

        $block->positions()->attach($position->id);

        return $block;
    }
}
