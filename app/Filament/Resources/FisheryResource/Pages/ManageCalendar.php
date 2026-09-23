<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\CalendarWindow;
use App\Enums\SaleUnavailabilityReason;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Services\FisheryNavigation;
use App\Services\SaleCalendar;
use App\Services\SaleCalendarCell;
use App\Services\SaleCalendarGrid;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Kalendarz podglądowy „co z tego wynika" — mechanizm G4 (zadanie 019).
 *
 * ⚠️ **Ekran jest WYŁĄCZNIE podglądem.** Nie ma z niego edycji ani skrótów „napraw to",
 * nie zapisuje niczego do bazy i nie dokłada ani jednej migracji. Kliknięcie prowadzi co
 * najwyżej do właściwego ekranu konfiguracji.
 *
 * ⚠️ To jest STRONA ZASOBU `FisheryResource`, nie strona panelu — trafia więc do obu paneli
 * bez dotykania providerów (`panel-wlasciciela.md` §6). Test sprawdza to jawnie, bo dopisanie
 * pozycji do `getPages()` dotyka klasy współdzielonej przez oba panele.
 *
 * ⚠️ **Cała logika siedzi w `SaleCalendar`, nie tutaj.** Strona wybiera okno i skład
 * uczestników; na pytanie „czy sprzedawalne i po ile" odpowiada warstwa oferty, a kalendarz
 * ją tylko układa w siatkę.
 */
class ManageCalendar extends Page
{
    // ⚠️ Bez tego cechy strona nie zna pojęcia rekordu: `Page` jest stroną zasobu, ale
    // dopiero `InteractsWithRecord` daje `resolveRecord()`, `getRecord()` i `authorizeAccess()`.
    use InteractsWithRecord;

    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected string $view = 'filament.resources.fishery-resource.pages.manage-calendar';

    /** Początek pokazywanego okna, jako data ISO — stan trzymany w adresie, nie w bazie. */
    public ?string $windowStart = null;

    public string $window = 'month';

    public ?int $seasonId = null;

    public int $anglers = 1;

    public int $companions = 0;

    /** `null` = najkrótszy kupowalny pobyt, czyli widok „ceny od". */
    public ?int $nights = null;

    public static function getNavigationLabel(): string
    {
        return __('Calendar');
    }

    public function getTitle(): string
    {
        return __('Calendar').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Calendar');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Calendar'));
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // ⚠️ Dostęp **jawnie** przez politykę rodzica — kalendarz nie ma własnej polityki ani
        // uprawnień Shielda (`autoryzacja.md` §5). Samo zawężenie zapytania w `resolveRecord()`
        // też broniłoby dostępu, ale niejawnie: reguła dostępu ma być widoczna w kodzie strony,
        // a nie wynikać z ubocznego skutku zakresu widoczności.
        abort_unless(auth()->user()?->can('view', $this->getRecord()) ?? false, 404);

        $calendar = $this->calendar();
        $seasons = $calendar->seasons();

        if ($seasons !== []) {
            $this->seasonId = (int) $seasons[0]->id;
        }

        $anchor = $calendar->anchor();

        if ($anchor instanceof CarbonImmutable) {
            $this->windowStart = $this->unit()->startFor($anchor)->toDateString();
        }
    }

    /**
     * ⚠️ **Zmiana sezonu przeskakuje na jednostkę zawierającą jego POCZĄTEK**, a nie na sam
     * dzień startu: kolumny mają wypadać tam, gdzie operator ich szuka.
     */
    public function updatedSeasonId(): void
    {
        foreach ($this->calendar()->seasons() as $period) {
            if ((int) $period->id === (int) $this->seasonId) {
                $this->windowStart = $this->unit()
                    ->startFor($this->asLocalDay($period->starts_on))
                    ->toDateString();

                return;
            }
        }
    }

    /** Przełączenie jednostki trzyma się tej samej zasady co zmiana sezonu. */
    public function updatedWindow(): void
    {
        if ($this->windowStart !== null) {
            $this->windowStart = $this->unit()->startFor($this->currentStart())->toDateString();
        }
    }

    public function previousWindow(): void
    {
        if ($this->canGoBack()) {
            $this->windowStart = $this->unit()->previous($this->currentStart())->toDateString();
        }
    }

    public function nextWindow(): void
    {
        if ($this->canGoForward()) {
            $this->windowStart = $this->unit()->next($this->currentStart())->toDateString();
        }
    }

    /**
     * ⚠️ **Przesuwanie nie wychodzi poza wybrany sezon** — na jego krańcach kierunek jest
     * niedostępny. Bez tej granicy operator wyjechałby w miesiące, w których siatka i tak
     * pokazuje same odmowy „poza sezonem".
     */
    public function canGoBack(): bool
    {
        $season = $this->season();

        if ($season === null) {
            return false;
        }

        return $this->unit()->previous($this->currentStart())
            ->gte($this->unit()->startFor($this->asLocalDay($season->starts_on)));
    }

    public function canGoForward(): bool
    {
        $season = $this->season();

        if ($season === null) {
            return false;
        }

        return $this->unit()->next($this->currentStart())
            ->lte($this->unit()->startFor($this->asLocalDay($season->ends_on)));
    }

    public function getGrid(): ?SaleCalendarGrid
    {
        if ($this->windowStart === null || $this->calendar()->missingSetup() !== null) {
            return null;
        }

        return $this->calendar()->grid(
            from: $this->currentStart(),
            unit: $this->unit(),
            anglers: max(1, $this->anglers),
            companions: max(0, $this->companions),
            nights: $this->nights,
        );
    }

    public function calendar(): SaleCalendar
    {
        return new SaleCalendar($this->fishery());
    }

    /**
     * Skrócona etykieta powodu, widoczna w komórce.
     *
     * ⚠️ **To jest INNY tekst niż `SaleUnavailabilityReason::label()`** i tak ma być: komórka
     * ma kilkanaście znaków szerokości, a `label()` zwraca całe zdanie. Nie próbuj zmieścić
     * jednego w drugim — wyjdzie tekst zły w obu miejscach.
     *
     * ⚠️ **Dwa powody „brak ceny" mają dwie RÓŻNE etykiety.** Sklejenie ich kasuje całą wartość
     * rozróżnienia wprowadzonego w 018: przy jednym trzeba dopisać stawkę, przy drugim poprawić
     * jedno pole w regule, która już istnieje.
     */
    public function shortLabelFor(SaleCalendarCell $cell): string
    {
        return match ($cell->reason) {
            SaleUnavailabilityReason::NoPriceDefined => __('no rate'),
            SaleUnavailabilityReason::NoCompanionPrice => __('no companion price'),
            SaleUnavailabilityReason::SaleBlocked => __('blocked'),
            SaleUnavailabilityReason::PositionWithdrawn => __('withdrawn'),
            SaleUnavailabilityReason::StayTooShort => __('too short'),
            SaleUnavailabilityReason::StayTooLong => __('too long'),
            SaleUnavailabilityReason::WeekendBroken, SaleUnavailabilityReason::WholeTermBroken => __('whole bundle'),
            SaleUnavailabilityReason::BeyondSaleHorizon => __('beyond horizon'),
            SaleUnavailabilityReason::BelowPresaleMinimum => __('presale minimum'),
            SaleUnavailabilityReason::FishingDayNotConfigured => __('no fishing day'),
            SaleUnavailabilityReason::NoSalePeriodDefined => __('no season'),
            default => __('off season'),
        };
    }

    /**
     * Pełny powód wraz ze **wskazaniem** — zakresem pakietu albo zasięgiem blokady.
     *
     * ⚠️ Bez wskazania odmowa jest bezużyteczna: operator wie, że się nie da, ale nie wie,
     * co poprawić ani ile dobrać.
     */
    public function tooltipFor(SaleCalendarCell $cell): string
    {
        $text = $cell->reason?->label() ?? '';

        if ($cell->bundleFirstDay instanceof CarbonImmutable && $cell->bundleLastDay instanceof CarbonImmutable) {
            $text .= ' '.__('The whole bundle runs :from–:to.', [
                'from' => $cell->bundleFirstDay->toDateString(),
                'to' => $cell->bundleLastDay->toDateString(),
            ]);
        }

        // ⚠️ `selection_label` jest nullable — zbiór zaznaczony ręcznie go nie ma, więc
        // wariant bez opisu kryterium podaje samą liczbę, nigdy pusty nawias.
        if ($cell->blockedPositionsCount !== null) {
            $text .= ' '.($cell->blockSelectionLabel !== null
                ? __('The block covers :count position(s) selected by ":label".', [
                    'count' => $cell->blockedPositionsCount,
                    'label' => $cell->blockSelectionLabel,
                ])
                : __('The block covers :count position(s).', ['count' => $cell->blockedPositionsCount]));
        }

        return trim($text);
    }

    public function unit(): CalendarWindow
    {
        return CalendarWindow::tryFrom($this->window) ?? CalendarWindow::Month;
    }

    public function currentStart(): CarbonImmutable
    {
        $timezone = $this->fishery()->timezone ?: 'Europe/Warsaw';

        return CarbonImmutable::parse($this->windowStart ?? 'today', $timezone)->startOfDay();
    }

    public function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }

    private function season(): ?SalePeriod
    {
        foreach ($this->calendar()->seasons() as $period) {
            if ((int) $period->id === (int) $this->seasonId) {
                return $period;
            }
        }

        return null;
    }

    private function asLocalDay(mixed $date): CarbonImmutable
    {
        $timezone = $this->fishery()->timezone ?: 'Europe/Warsaw';

        return CarbonImmutable::parse((string) $date->toDateString(), $timezone)->startOfDay();
    }
}
