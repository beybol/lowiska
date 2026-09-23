<?php

namespace Tests\Feature;

use App\Enums\CalendarWindow;
use App\Enums\PositionStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Position;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use App\Services\SaleCalendar;
use App\Services\SaleCalendarCell;
use App\Services\SaleCalendarGrid;
use App\Services\SaleCalendarRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\StayFixtures;

/**
 * Warstwa danych kalendarza podglądowego — zadanie 019.
 *
 * ⚠️ Czas jest **zamrożony** we wszystkich testach, bo kotwica widoku zależy od „dziś",
 * a siatka od strefy czasowej łowiska.
 *
 * Kalendarz odniesienia 2026: 01.05 pt · 02.05 sob · 03.05 nd · 06.05 śr · 07.05 czw.
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-04 09:00', 'Europe/Warsaw'));
});

afterEach(function () {
    Date::setTestNow();
});

/** Komórka danej doby z pierwszego wiersza siatki. */
function cellOn(SaleCalendarGrid $grid, string $day, int $row = 0): SaleCalendarCell
{
    foreach ($grid->rows[$row]->cells as $cell) {
        if ($cell->night->toDateString() === $day) {
            return $cell;
        }
    }

    throw new \RuntimeException("Brak komórki na {$day}.");
}

test('okno miesięczne daje tyle kolumn, ile ma miesiąc', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $calendar = new SaleCalendar($fishery->fresh());

    $july = $calendar->grid(CarbonImmutable::parse('2026-07-10'), CalendarWindow::Month);
    $february = $calendar->grid(CarbonImmutable::parse('2026-02-10'), CalendarWindow::Month);

    expect($july->days)->toHaveCount(31)
        ->and($february->days)->toHaveCount(28)
        ->and($july->days[0]->toDateString())->toBe('2026-07-01');
});

test('okno tygodniowe daje siedem kolumn od poniedziałku', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    // 2026-05-07 to czwartek; tydzień zawierający go zaczyna się 04.05 (poniedziałek).
    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-07'), CalendarWindow::Week);

    expect($grid->days)->toHaveCount(7)
        ->and($grid->days[0]->toDateString())->toBe('2026-05-04');
});

test('kotwicą jest dziś, gdy dzisiejsza data mieści się w sezonie', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect((new SaleCalendar($fishery->fresh()))->anchor()->toDateString())->toBe('2026-05-04');
});

/**
 * ⚠️ To jest powód, dla którego kotwicą NIE jest „dziś": łowisko ustawiające przyszły sezon
 * zobaczyłoby same odmowy „poza sezonem", czyli nic.
 */
test('kotwicą jest początek najbliższego sezonu, gdy dziś jest poza sezonami', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    $fishery->salePeriods()->update(['starts_on' => '2026-11-01', 'ends_on' => '2027-03-31']);

    expect((new SaleCalendar($fishery->fresh()))->anchor()->toDateString())->toBe('2026-11-01');
});

test('lista sezonów pomija zakończone, a zawiera trwające i przyszłe', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2025-01-01',
        'ends_on' => '2025-12-31',
    ]);
    SalePeriod::factory()->create([
        'fishery_id' => $fishery->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
    ]);

    $seasons = (new SaleCalendar($fishery->fresh()))->seasons();
    $years = array_map(static fn (SalePeriod $p): string => $p->starts_on->format('Y'), $seasons);

    expect($years)->toBe(['2026', '2027']);
});

test('każde stanowisko dostaje wiersz, a wycofane jeden komunikat zamiast komórek', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    Position::factory()->create([
        'fishery_id' => $fishery->id,
        'name' => 'Zzz wycofane',
        'status' => PositionStatus::Withdrawn,
    ]);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-01'), CalendarWindow::Week);

    expect($grid->rows)->toHaveCount(2);

    $withdrawn = array_values(array_filter(
        $grid->rows,
        static fn (SaleCalendarRow $row): bool => $row->withdrawn,
    ));

    expect($withdrawn)->toHaveCount(1)
        ->and($withdrawn[0]->cells)->toBe([])
        ->and($grid->rows[0]->position->id)->toBe($position->id);
});

/**
 * ⚠️ Kwota bez liczby dób wprowadza w błąd — 130,00 zł za dobę i 520,00 zł za czterodobowy
 * pakiet wyglądają wtedy jak ceny tego samego.
 */
test('komórka sprzedawalna niesie kwotę RAZEM z liczbą dób', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    $cell = cellOn($grid, '2026-05-06');

    expect($cell->sellable)->toBeTrue()
        ->and($cell->nights)->toBe(1)
        ->and($cell->totalInCents)->toBe(7000);
});

/**
 * ⚠️ Odtwarza kryterium K1a: operator ma zobaczyć skutek dopłaty bez otwierania cennika.
 */
test('złożenie cen widać — czwartek 90 zł, środa 70 zł', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc', [
        'weekdays' => [4, 5, 6, 7],
        'anglers_count' => 1,
    ]);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    expect(cellOn($grid, '2026-05-07')->totalInCents)->toBe(9000)
        ->and(cellOn($grid, '2026-05-06')->totalInCents)->toBe(7000);
});

test('dopłata dla łowiącego nie zmienia kwoty po dołożeniu osoby towarzyszącej', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::surcharge($fishery, 20.00, 'Wylacznosc', ['anglers_count' => 1]);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week, anglers: 1, companions: 1);

    expect(cellOn($grid, '2026-05-06')->totalInCents)->toBe(9000);
});

test('stała długość pobytu przelicza całą siatkę', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week, nights: 3);

    $cell = cellOn($grid, '2026-05-06');

    expect($cell->nights)->toBe(3)
        ->and($cell->totalInCents)->toBe(21000);
});

/**
 * ⚠️ Zlewanie pakietów jest z założenia niewidoczne w formularzu — kalendarz jest pierwszym
 * miejscem, w którym operator je zobaczy (ADR-013).
 */
test('zlewanie pakietów widać: doby w środku pakietu mówią „zacznij wcześniej"', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    StayFixtures::rate($fishery, 70.00);
    // Święto śr–pt (29.04–01.05) zlewa się z weekendem pt–sob → pakiet śr–sob, 4 doby.
    StayFixtures::wholeTerm($fishery, '2026-04-29', 3);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-04-27'), CalendarWindow::Week);

    expect(cellOn($grid, '2026-04-29')->nights)->toBe(4);

    foreach (['2026-04-30', '2026-05-01', '2026-05-02'] as $inside) {
        $cell = cellOn($grid, $inside);

        expect($cell->sellable)->toBeFalse()
            ->and($cell->startsEarlierElsewhere())->toBeTrue()
            ->and($cell->startEarlierOn->toDateString())->toBe('2026-04-29');
    }
});

/**
 * ⚠️ Przycinanie jest per stanowisko, bo zależy od blokad — i to jest argument za siatką
 * stanowiska × doby zamiast jednego wiersza na łowisko.
 */
test('przycinanie pakietu widać: piątek sprzedaje się jednodobowo mimo reguły weekendu', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition(['weekend_days' => [5, 6]]);
    StayFixtures::rate($fishery, 70.00);
    // ⚠️ Blokada działa przez PRZECIĘCIE z dobą, nie przez zawieranie: doba zaczynająca się
    // 01.05 kończy się 02.05, więc blokada 02.05 ucięłaby także piątek. Żeby przyciąć samą
    // sobotę, blokada musi stać 03.05 (`dostepnosc.md` — asymetria okresów i blokad).
    StayFixtures::blockSale($fishery, $position, '2026-05-03', '2026-05-03');

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-04-27'), CalendarWindow::Week);

    expect(cellOn($grid, '2026-05-01')->nights)->toBe(1);
});

test('podpowiedź blokady zna liczbę objętych stanowisk i sposób wyboru', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $second = Position::factory()->create([
        'fishery_id' => $fishery->id,
        'name' => 'Drugie',
        'status' => PositionStatus::Available,
    ]);

    $block = StayFixtures::blockSale($fishery, $position, '2026-05-06', '2026-05-06');
    $block->positions()->attach($second->id);
    $block->update(['selection_label' => 'Brzeg wschodni']);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    $cell = cellOn($grid, '2026-05-06');

    expect($cell->reason)->toBe(SaleUnavailabilityReason::SaleBlocked)
        ->and($cell->blockedPositionsCount)->toBe(2)
        ->and($cell->blockSelectionLabel)->toBe('Brzeg wschodni');
});

/**
 * ⚠️ `selection_label` jest nullable — zbiór zaznaczony ręcznie go nie ma, więc potrzebny
 * jest wariant bez opisu kryterium, nigdy pusty nawias.
 */
test('blokada zaznaczona ręcznie podaje samą liczbę, bez opisu kryterium', function () {
    [$fishery, $position] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::blockSale($fishery, $position, '2026-05-06', '2026-05-06');

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    $cell = cellOn($grid, '2026-05-06');

    expect($cell->blockedPositionsCount)->toBe(1)
        ->and($cell->blockSelectionLabel)->toBeNull();
});

test('dziura w cenniku jest odmową „brak ceny", a nie kwotą zerową', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-06-01']);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    $cell = cellOn($grid, '2026-05-06');

    expect($cell->sellable)->toBeFalse()
        ->and($cell->reason)->toBe(SaleUnavailabilityReason::NoPriceDefined)
        ->and($cell->totalInCents)->toBeNull();
});

/**
 * ⚠️ Zachowanie POPRAWNE, nie usterka: to najszybsza diagnoza, jaką ten ekran daje —
 * operator widzi w jednym ruchu, że cennik nie przewiduje towarzyszenia.
 */
test('dołożenie osoby towarzyszącej przestawia całą siatkę w „brak ceny dla towarzyszącej"', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    PriceRule::factory()->amount(70.00)->companionAmount(null)->create(['fishery_id' => $fishery->id]);

    $calendar = new SaleCalendar($fishery->fresh());

    $alone = $calendar->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);
    $withCompanion = $calendar->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week, companions: 1);

    expect(cellOn($alone, '2026-05-06')->sellable)->toBeTrue();

    foreach ($withCompanion->rows[0]->cells as $cell) {
        expect($cell->sellable)->toBeFalse()
            ->and($cell->reason)->toBe(SaleUnavailabilityReason::NoCompanionPrice)
            ->and($cell->reason)->not->toBe(SaleUnavailabilityReason::NoPriceDefined);
    }
});

/**
 * ⚠️ Licznik pokazuje się przy KAŻDYM nachodzeniu, także przy identycznych kwotach — dwie
 * identyczne reguły to najczystszy przypadek bałaganu i bez tego byłby niewidoczny.
 */
test('nachodzenie stawek widać także wtedy, gdy nie zmienia ceny', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 70.00);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    expect($grid->overlappingRates(CarbonImmutable::parse('2026-05-06')))->toBe(2)
        ->and($grid->ratesFor(CarbonImmutable::parse('2026-05-06')))->toHaveCount(2);
});

test('jedna pasująca stawka nie daje licznika nachodzenia', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    expect($grid->overlappingRates(CarbonImmutable::parse('2026-05-06')))->toBe(0);
});

/**
 * ⚠️ Oznaczenie jest JEDNO NA REGULE, nie na każdej dobie jej okresu.
 */
test('martwa stawka jest oznaczona raz, na regule', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00, ['first_day_on' => '2026-01-01']);
    $dead = StayFixtures::rate($fishery, 90.00, ['first_day_on' => '2026-05-01', 'last_day_on' => '2026-05-31']);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    expect($grid->deadRates)->toHaveCount(1)
        ->and($grid->isDeadRate($dead))->toBeTrue();
});

/**
 * ⚠️ Kandydaci zależą WYŁĄCZNIE od daty, więc diagnostyka pobiera się raz na okno, a nie
 * raz na komórkę. Przy Klasztornym to różnica między 31 a 806 wywołaniami.
 */
test('diagnostyka nie zależy od liczby stanowisk ani od składu uczestników', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::rate($fishery, 90.00);

    foreach (range(1, 4) as $i) {
        Position::factory()->create([
            'fishery_id' => $fishery->id,
            'name' => 'Stanowisko '.$i,
            'status' => PositionStatus::Available,
        ]);
    }

    $calendar = new SaleCalendar($fishery->fresh());
    $day = CarbonImmutable::parse('2026-05-06');

    $one = $calendar->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);
    $many = $calendar->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week, anglers: 3, companions: 2);

    expect($one->rows)->toHaveCount(5)
        ->and($one->overlappingRates($day))->toBe(2)
        ->and($many->overlappingRates($day))->toBe($one->overlappingRates($day));
});

test('zwolnienie świąteczne widać w cenach od', function () {
    // ⚠️ Zwolnienie świąteczne z minimum jest BEZWARUNKOWE — nie ma flagi na łowisku.
    // Pobyt obejmujący dobę święta jest zwolniony z `min_nights` z samej definicji (017).
    [$fishery] = StayFixtures::fisheryWithPosition(['min_nights' => 5]);
    StayFixtures::rate($fishery, 70.00);
    StayFixtures::wholeTerm($fishery, '2026-05-06', 3);

    $grid = (new SaleCalendar($fishery->fresh()))
        ->grid(CarbonImmutable::parse('2026-05-04'), CalendarWindow::Week);

    expect(cellOn($grid, '2026-05-06')->nights)->toBe(3);
});

test('stany puste mówią, czego brakuje', function () {
    [$noDay] = StayFixtures::fisheryWithPosition(['day_start_time' => null, 'day_end_time' => null]);
    expect((new SaleCalendar($noDay->fresh()))->missingSetup())->toBe('fishing_day');

    [$noPeriod] = StayFixtures::fisheryWithPosition();
    $noPeriod->salePeriods()->delete();
    expect((new SaleCalendar($noPeriod->fresh()))->missingSetup())->toBe('sale_period');

    [$noPosition] = StayFixtures::fisheryWithPosition();
    $noPosition->positions()->delete();
    expect((new SaleCalendar($noPosition->fresh()))->missingSetup())->toBe('position');

    [$complete] = StayFixtures::fisheryWithPosition();
    expect((new SaleCalendar($complete->fresh()))->missingSetup())->toBeNull();
});

/**
 * ⚠️ To jest ZWYKŁE sprawdzenie istnienia, nie druga implementacja wykrywania dziur —
 * pełna analiza mieszka w walidacji zadania 018 i ma tam zostać.
 */
test('brak stawek jest osobnym sygnałem, nie zastępuje siatki', function () {
    [$fishery] = StayFixtures::fisheryWithPosition();

    expect((new SaleCalendar($fishery->fresh()))->hasNoRates())->toBeTrue()
        ->and((new SaleCalendar($fishery->fresh()))->missingSetup())->toBeNull();

    StayFixtures::rate($fishery, 70.00);

    expect((new SaleCalendar($fishery->fresh()))->hasNoRates())->toBeFalse();
});
