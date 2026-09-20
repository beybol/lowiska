<?php

namespace Tests\Feature;

use App\Enums\PositionStatus;
use App\Enums\SaleMode;
use App\Enums\SaleUnavailabilityReason;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\SalePeriod;
use App\Services\PositionAvailability;

/**
 * Jedno źródło prawdy o dostępności (ADR-012): skład czterech warunków w stałej
 * kolejności i reguła PRZECIĘCIA dla blokad (ADR-010).
 *
 * ⚠️ Para „doba stykająca się z oknem" kontra „doba przecinająca okno" jest sprawdzana
 * osobnymi asercjami — błędna implementacja (zawieranie zamiast przecięcia albo
 * porównanie po datach zamiast po momentach) zaliczy obie strony naraz.
 */
function sellingFishery(): Fishery
{
    $fishery = Fishery::factory()->create([
        'sale_mode' => SaleMode::DailyPeriod,
        'day_start_time' => '15:00:00',
        'day_end_time' => '15:00:00',
        'timezone' => 'Europe/Warsaw',
    ]);

    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-09-30',
    ]);

    return $fishery;
}

function sellablePosition(Fishery $fishery): Position
{
    return Position::factory()->create([
        'fishery_id' => $fishery->id,
        'status' => PositionStatus::Available,
    ]);
}

test('a position in a sale period without blocks is sellable', function () {
    $position = sellablePosition(sellingFishery());

    expect((new PositionAvailability($position))->isSellable('2026-06-15'))->toBeTrue();
});

test('a withdrawn position is refused regardless of dates', function () {
    $fishery = sellingFishery();
    $position = Position::factory()->create([
        'fishery_id' => $fishery->id,
        'status' => PositionStatus::Withdrawn,
    ]);

    $verdict = (new PositionAvailability($position))->availability('2026-06-15');

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::PositionWithdrawn);
});

test('a day outside the sale period is refused even without any block', function () {
    $position = sellablePosition(sellingFishery());

    $verdict = (new PositionAvailability($position))->availability('2026-01-15');

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::StartsBeforeSalePeriod);
});

test('a day intersecting a sale block is refused even when only part of the day overlaps', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-11',
        'ends_on' => '2026-06-11',
    ]);
    $block->positions()->attach($position->id);

    // Doba 10.06 15:00 → 11.06 15:00 wchodzi w blokadę z 11.06 tylko drugą połową —
    // i mimo to jest objęta. To jest reguła przecięcia, nie zawierania.
    $verdict = (new PositionAvailability($position))->availability('2026-06-10');

    expect($verdict->sellable)->toBeFalse()
        ->and($verdict->reason)->toBe(SaleUnavailabilityReason::SaleBlocked);
});

test('a day touching the block window without intersecting it stays sellable', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-12',
        'ends_on' => '2026-06-12',
    ]);
    $block->positions()->attach($position->id);

    // Doba 10.06 15:00 → 11.06 15:00 kończy się PRZED północą otwierającą 12.06 —
    // styka się z oknem po dacie, ale go nie przecina po momentach.
    $availability = new PositionAvailability($position);

    expect($availability->isSellable('2026-06-10'))->toBeTrue()
        // Doba 11.06 15:00 → 12.06 15:00 już wchodzi w 12.06 — przecina.
        ->and($availability->isSellable('2026-06-11'))->toBeFalse();
});

test('an open ended block applies to every day from its start', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->openEnded()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-15',
    ]);
    $block->positions()->attach($position->id);

    $availability = new PositionAvailability($position);

    expect($availability->isSellable('2026-06-13'))->toBeTrue()
        ->and($availability->isSellable('2026-09-01'))->toBeFalse();
});

test('a block on another position does not touch this one', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $other = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->create(['fishery_id' => $fishery->id]);
    $block->positions()->attach($other->id);

    // Zbiór jest zmaterializowany — blokada dotyczy DOKŁADNIE stanowisk z listy.
    expect((new PositionAvailability($position))->isSellable('2026-06-15'))->toBeTrue();
});

test('when several reasons apply the first in the permanence order wins', function () {
    $fishery = sellingFishery();
    $position = Position::factory()->create([
        'fishery_id' => $fishery->id,
        'status' => PositionStatus::Withdrawn,
    ]);
    $block = AvailabilityBlock::factory()->create(['fishery_id' => $fishery->id]);
    $block->positions()->attach($position->id);

    // Wycofane ORAZ zablokowane ORAZ poza sezonem — raportuje stan stanowiska, bo to
    // przyczyna najtrwalsza (ADR-012). Kolejność jest umową, nie skutkiem kosztu zapytania.
    $verdict = (new PositionAvailability($position))->availability('2026-01-15');

    expect($verdict->reason)->toBe(SaleUnavailabilityReason::PositionWithdrawn);
});

test('the sale period is checked before blocks', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-01-10',
        'ends_on' => '2026-01-20',
    ]);
    $block->positions()->attach($position->id);

    // Poza sezonem I zablokowane — wygrywa sezon, bo jest trwalszy od blokady.
    $verdict = (new PositionAvailability($position))->availability('2026-01-15');

    expect($verdict->reason)->toBe(SaleUnavailabilityReason::StartsBeforeSalePeriod);
});

test('an attribute suspension does not refuse the sale', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $attribute = PositionAttribute::factory()->create(['name' => 'Pomost']);
    $block = AvailabilityBlock::factory()->suspending($attribute)->create(['fishery_id' => $fishery->id]);
    $block->positions()->attach($position->id);

    $availability = new PositionAvailability($position);

    // Stanowisko z zawieszonym pomostem NADAL się sprzedaje — tylko bez pomostu.
    expect($availability->isSellable('2026-06-15'))->toBeTrue()
        ->and($availability->suspendedAttributes('2026-06-15')->pluck('id')->all())->toBe([$attribute->id])
        ->and($availability->suspendedAttributes('2026-07-15'))->toBeEmpty();
});

test('a suspension leaves the attribute value on the position untouched', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $attribute = PositionAttribute::factory()->create();
    $position->attributeValues()->create([
        'position_attribute_id' => $attribute->id,
        'value_flag' => true,
    ]);
    $block = AvailabilityBlock::factory()->suspending($attribute)->create(['fishery_id' => $fishery->id]);
    $block->positions()->attach($position->id);

    // Wartość NIE jest ruszana: po upływie okresu wszystko wraca samo, bez operacji.
    expect($position->attributeValues()->where('position_attribute_id', $attribute->id)->value('value_flag'))
        ->toEqual(1);
});

test('sellable days between two dates skip the blocked ones', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $block = AvailabilityBlock::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2026-06-12',
        'ends_on' => '2026-06-12',
    ]);
    $block->positions()->attach($position->id);

    $days = (new PositionAvailability($position))->sellableDaysBetween('2026-06-10', '2026-06-15');

    // W zakresie 10–15.06 zawarte są doby 10–14.06 (ostatnia kończy się 15.06 15:00);
    // blokada 12.06 zdejmuje doby 11.06 (kończy się 12.06) i 12.06 (zaczyna się 12.06).
    expect(array_map(fn ($day) => $day->startsOn->toDateString(), $days))
        ->toBe(['2026-06-10', '2026-06-13', '2026-06-14']);
});

test('the service relies on the calendar so a changed sale period changes its verdict', function () {
    $fishery = sellingFishery();
    $position = sellablePosition($fishery);
    $availability = new PositionAvailability($position);

    expect($availability->isSellable('2026-09-15'))->toBeTrue();

    // Skrócenie sezonu bez dotykania kodu tego zadania musi zmienić wynik — dowód,
    // że usługa WOŁA kalendarz zamiast powtarzać jego reguły.
    SalePeriod::query()->where('fishery_id', $fishery->id)->update(['ends_on' => '2026-08-31']);

    expect((new PositionAvailability($position->fresh()))->availability('2026-09-15')->reason)
        ->toBe(SaleUnavailabilityReason::OutsideSalePeriod);
});
