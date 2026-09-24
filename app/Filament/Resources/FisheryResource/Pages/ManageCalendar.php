<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\CalendarWindow;
use App\Enums\SaleUnavailabilityReason;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Models\PriceRule;
use App\Models\SalePeriod;
use App\Services\AmountFormatter;
use App\Services\FisheryNavigation;
use App\Services\PositionServiceProblem;
use App\Services\SaleCalendar;
use App\Services\SaleCalendarCell;
use App\Services\SaleCalendarGrid;
use App\Services\SaleCalendarRow;
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

    private ?SaleCalendar $calendar = null;

    public int $anglers = 1;

    public int $companions = 0;

    /** `null` = najkrótszy kupowalny pobyt, czyli widok „ceny od". */
    public ?int $nights = null;

    /** Górne granice wejścia od klienta — patrz `clamp()`. */
    private const MAX_PARTY = 50;

    private const MAX_NIGHTS = 365;

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
            anglers: $this->clamp($this->anglers, 1, self::MAX_PARTY),
            companions: $this->clamp($this->companions, 0, self::MAX_PARTY),
            nights: $this->nights === null ? null : $this->clamp($this->nights, 1, self::MAX_NIGHTS),
        );
    }

    public function calendar(): SaleCalendar
    {
        // ⚠️ Memoizacja na czas JEDNEGO żądania — instancja ginie razem z nim, więc nie jest to
        // bufor werdyktu zakazany przez `dostepnosc.md` §2. Bez niej widok tworzy kalendarz
        // pięć razy na render i pięć razy odpytuje o sezony.
        return $this->calendar ??= new SaleCalendar($this->fishery());
    }

    /**
     * Treść podpowiedzi przy liczniku nachodzących stawek.
     *
     * ⚠️ **Sam licznik nie wystarcza** — kryterium 019 i ADR-014 wymagają, żeby podpowiedź
     * zamykała pytanie „czemu widzę 70, skoro wpisałem 90". Dlatego wypisuje zwycięzcę
     * i kwoty przegranych, a nie samą liczbę pasujących reguł.
     *
     * @param  array<int, PriceRule>  $rates  w kolejności rozstrzygania, zwycięzca pierwszy
     */
    public function overlapTooltip(array $rates): string
    {
        if ($rates === []) {
            return '';
        }

        $winner = array_shift($rates);
        $others = array_map(static fn ($rule): string => (string) $rule->amount, $rates);

        return __('The cheapest rate wins: :winner. Also matching: :others.', [
            'winner' => (string) $winner->amount,
            'others' => implode(', ', $others),
        ]);
    }

    /**
     * Plakietka przy nazwie stanowiska: „3 usługi", a przy niedostępnej „3 usługi · 1 niedostępna".
     *
     * ⚠️ Najważniejsza informacja — że coś jest niedostępne — ma być widoczna BEZ klikania, więc
     * dopisek siedzi w samej plakietce, a nie tylko w podpowiedzi (zadanie 020).
     */
    public function servicesBadge(SaleCalendarRow $row): string
    {
        $count = count($row->services);
        $text = trans_choice(':count service|:count services', $count, ['count' => $count]);
        $unavailable = $row->unavailableServices();

        if ($unavailable > 0) {
            $text .= ' · '.trans_choice(':count unavailable|:count unavailable', $unavailable, ['count' => $unavailable]);
        }

        return $text;
    }

    /**
     * Pełna lista usług stanowiska w podpowiedzi plakietki — w kolejności z `PositionServices`
     * (obowiązkowe, potem alfabetycznie): nazwa, cena podstawowa z jednostką albo „bezpłatna",
     * „obowiązkowa", limit egzemplarzy informacyjnie, a dla niedostępnej — przyczyna i daty.
     *
     * ⚠️ Kalendarz pokazuje OBRAZ KONFIGURACJI, nie liczy rachunku: bez kwot zależnych od liczby
     * dób czy osób i bez wolnych egzemplarzy („X z N") — to należy do modułu rezerwacji.
     */
    public function servicesTooltip(SaleCalendarRow $row): string
    {
        $currency = $this->fishery()->currency?->name;
        $lines = [];

        foreach ($row->services as $status) {
            $service = $status->service;
            $parts = [$service->name, $service->priceLabel($currency)];

            if ($status->isRequired) {
                $parts[] = __('required');
            }

            if ($service->available_count !== null) {
                $parts[] = __(':count pcs', ['count' => $service->available_count]);
            }

            $line = implode(' · ', $parts);

            if (! $status->isAvailable()) {
                $line .= ' — '.__('unavailable').': '.implode('; ', array_map(
                    static fn (PositionServiceProblem $problem): string => $problem->description(),
                    $status->problems,
                ));
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Wpis listy martwych stawek:„[Cennik 2026 — ]90,00 PLN · 2026-07-01–2026-08-31".
     *
     * ⚠️ **Kwota bez kontekstu nie wystarcza** — stawka nie musi mieć nazwy, więc operator ma
     * trafić do właściwego wiersza cennika po kwocie I zakresie dat (zadanie 023, poz. 4).
     */
    public function deadRateDescription(PriceRule $rule): string
    {
        $text = AmountFormatter::cents($rule->amountInCents(), $this->fishery()->currency?->name);

        $period = PriceRule::periodText(
            $rule->first_day_on?->toDateString(),
            $rule->last_day_on?->toDateString(),
        );

        if ($period !== null) {
            $text .= ' · '.$period;
        }

        return filled($rule->label) ? $rule->label.' — '.$text : $text;
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

    /**
     * ⚠️ **`windowStart` przychodzi OD KLIENTA**, więc nie wolno jej podać wprost parserowi dat:
     * `$wire.set('windowStart', 'x')` rzuciłoby `InvalidFormatException` i wywróciło cały ekran.
     * Kształt sprawdzamy wzorcem, a przy czymkolwiek innym wracamy do kotwicy — podgląd ma się
     * wtedy pokazać, a nie wysypać.
     */
    public function currentStart(): CarbonImmutable
    {
        $timezone = $this->fishery()->timezone ?: 'Europe/Warsaw';

        if (is_string($this->windowStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->windowStart) === 1) {
            try {
                return CarbonImmutable::parse($this->windowStart, $timezone)->startOfDay();
            } catch (\Throwable) {
                // Wzorzec przepuszcza „2026-13-45"; wtedy też schodzimy do kotwicy.
            }
        }

        return $this->calendar()->anchor() ?? CarbonImmutable::now($timezone)->startOfDay();
    }

    /**
     * ⚠️ **Każda właściwość publiczna komponentu to dane od klienta** (`CLAUDE.md`). Atrybut
     * `min` w HTML nie jest walidacją serwerową: bez tej klamry `nights = 0` leciało do warstwy
     * oferty i kończyło się wyjątkiem, czyli **piątką na całym ekranie**, a wartość rzędu stu
     * tysięcy budowała tyleż dób **na każdą komórkę**.
     */
    private function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
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
