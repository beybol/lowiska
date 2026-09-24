<?php

use App\Models\Fishery;
use App\Services\WeekdayNights;

/*
 * Dom wiedzy o dobach tygodnia (zadanie 023, poz. 10).
 *
 * ⚠️ W katalogu `Feature`, a nie `Unit`, bo klasa tłumaczy teksty i formatuje nazwy dni
 * w locale aplikacji — `Unit` nie uruchamia Laravela.
 */

beforeEach(function (): void {
    app()->setLocale('pl');
});

it('pusty zbiór daje tekst podany przez ekran', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    expect($nights->summary([], 'Każda doba.'))->toBe('Każda doba.')
        ->and($nights->summary(null, 'Brak'))->toBe('Brak')
        ->and($nights->shortForm([]))->toBe('');
});

it('zbiór ciągły to jeden odcinek od pierwszej doby do końca ostatniej', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    expect($nights->summary([5, 6], '—'))->toBe('Od pt 15:00 do ndz 15:00 · 2 doby')
        ->and($nights->shortForm([5, 6]))->toBe('pt→ndz');
});

it('zbiór nieciągły rozkłada się na osobne odcinki, a liczba dób jest łączna', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    expect($nights->summary([1, 3], '—'))->toBe('Od pon 15:00 do wt 15:00; Od śr 15:00 do czw 15:00 · 2 doby')
        ->and($nights->shortForm([1, 3]))->toBe('pon, śr');
});

it('zbiór cykliczny {7, 1} to JEDEN odcinek niedziela → wtorek', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    // Łańcuchy, bo tak przysyła je stan formularza.
    expect($nights->summary(['7', '1'], '—'))->toBe('Od ndz 15:00 do wt 15:00 · 2 doby')
        ->and(WeekdayNights::runs([1, 7]))->toBe([[7, 1]]);
});

it('bez godzin doby podsumowanie mówi o dobach bez godzin, a chipy nie mają linii godzin', function (): void {
    $fishery = Fishery::factory()->create(['day_start_time' => null, 'day_end_time' => null]);
    $nights = WeekdayNights::forFishery($fishery);

    expect($nights->hasHours())->toBeFalse()
        ->and($nights->summary([5, 6], '—'))->toBe('Od pt do ndz · 2 doby')
        ->and($nights->options()[5]->toHtml())->toContain('pt → sob')
        ->and(substr_count($nights->options()[5]->toHtml(), 'display:block'))->toBe(1);
});

it('chip jest dwuwierszowy: przedział doby i godziny z łowiska', function (): void {
    $fishery = Fishery::factory()->create(['day_start_time' => '15:00:00', 'day_end_time' => '15:00:00']);
    $html = WeekdayNights::forFishery($fishery)->options()[5]->toHtml();

    expect($html)->toContain('pt → sob')
        ->and($html)->toContain('15:00 → 15:00')
        ->and(substr_count($html, 'display:block'))->toBe(2);
});

it('daje dokładnie siedem chipów, po jednym na dobę ISO 1–7, z kompletnym znacznikiem', function (): void {
    $options = (new WeekdayNights('15:00', '16:00'))->options();

    expect(array_keys($options))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($options[7]->toHtml())->toBe(
            '<span style="display:block; font-weight:600">ndz → pon</span>'
            .'<span style="display:block; font-size:.75em; font-weight:400; opacity:.75">15:00 → 16:00</span>'
        )
        ->and((new WeekdayNights)->options()[1]->toHtml())
        ->toBe('<span style="display:block; font-weight:600">pon → wt</span>');
});

it('wartości z bazy i z locale są escapowane w znaczniku chipa', function (): void {
    $html = (new WeekdayNights('<b>', '15:00'))->options()[1]->toHtml();

    expect($html)->toContain('&lt;b&gt; → 15:00')
        ->and($html)->not->toContain('<b>');
});

it('godziny istnieją tylko wtedy, gdy łowisko ma OBIE granice doby', function (): void {
    expect((new WeekdayNights('15:00', null))->hasHours())->toBeFalse()
        ->and((new WeekdayNights(null, '15:00'))->hasHours())->toBeFalse()
        ->and((new WeekdayNights('15:00', null))->hours())->toBeNull()
        ->and((new WeekdayNights('15:00', '15:00'))->hours())->toBe('15:00 → 15:00');
});

it('godziny z bazy są przycinane do HH:MM', function (): void {
    $fishery = Fishery::factory()->create(['day_start_time' => '06:30:00', 'day_end_time' => '18:45:00']);

    expect(WeekdayNights::forFishery($fishery)->hours())->toBe('06:30 → 18:45');
});

it('stan formularza jest normalizowany: łańcuchy, duplikaty, wartości spoza 1–7 i kolejność', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    expect($nights->summary(['6', 5, '6', 0, 8, -1], '—'))->toBe('Od pt 15:00 do ndz 15:00 · 2 doby')
        ->and($nights->shortForm(['3', '1']))->toBe('pon, śr')
        ->and($nights->summary('5', 'pusto'))->toBe('pusto');
});

it('ciąg sześciu dób nie zawija się w pełny tydzień', function (): void {
    $nights = new WeekdayNights('15:00', '15:00');

    expect(WeekdayNights::runs([1, 2, 3, 4, 5, 6]))->toBe([[1, 2, 3, 4, 5, 6]])
        ->and($nights->summary([1, 2, 3, 4, 5, 6], '—'))->toBe('Od pon 15:00 do ndz 15:00 · 6 dób')
        ->and($nights->shortForm([1, 2, 3, 4, 5, 6]))->toBe('pon→ndz')
        ->and($nights->shortForm(range(1, 7)))->toBe('7 dób');
});

it('wszystkie siedem dób nie ma początku ani końca — zostaje sama liczba', function (): void {
    expect((new WeekdayNights('15:00', '15:00'))->summary(range(1, 7), '—'))->toBe('7 dób');
});
