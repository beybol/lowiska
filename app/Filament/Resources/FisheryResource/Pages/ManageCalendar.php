<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\CalendarLayer;
use App\Enums\CalendarWindow;
use App\Enums\SaleUnavailabilityReason;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Filament\Resources\ReservationResource\ReservationActions;
use App\Filament\Resources\ReservationResource\ReservationFormSubmission;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\SalePeriod;
use App\Services\AmountFormatter;
use App\Services\AvailabilityBlockWriter;
use App\Services\FisheryNavigation;
use App\Services\FishingDayCalendar;
use App\Services\PositionServiceProblem;
use App\Services\ReservationCalendar;
use App\Services\ReservationCalendarGrid;
use App\Services\SaleCalendar;
use App\Services\SaleCalendarCell;
use App\Services\SaleCalendarGrid;
use App\Services\SaleCalendarRow;
use App\Services\StayOffer;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Kalendarz łowiska — mechanizm G4: JEDNA siatka, dwie warstwy (zadania 019, 042).
 *
 * - **„Oferta"** (`?layer=offer`) — podgląd konfiguracji sprzedaży „co z tego wynika" (019).
 *   ⚠️ **Ta warstwa jest WYŁĄCZNIE podglądem.** Nie ma z niej edycji ani skrótów „napraw to"
 *   i niczego nie zapisuje. Kliknięcie prowadzi co najwyżej do właściwego ekranu konfiguracji.
 * - **„Rezerwacje"** (domyślna) — warstwa operacyjna: paski rezerwacji i blokady na stanowiskach,
 *   klik w dobę → zaznaczenie i popover w wierszu, krótki formularz rezerwacji, blokada, akcje
 *   na rezerwacji (modale z `ReservationActions`). Siatkę składa `ReservationCalendar`; zapis idzie
 *   wyłącznie przez `ReservationBooking`.
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

    /** Warstwa kalendarza — w adresie (`?layer=`); wartość spoza enuma → domyślna „Rezerwacje". */
    #[Url]
    public string $layer = 'reservations';

    /** Filtry warstwy „Rezerwacje": grupa stanowisk i status (`pending` · `confirmed` · `paid`). */
    public ?int $groupId = null;

    public ?string $statusFilter = null;

    /**
     * Zaznaczenie wolnych dób (popover w wierszu). ⚠️ Dane od klienta — każda metoda sprawdza
     * stanowisko w łowisku i dobę w siatce, zanim czegokolwiek użyje.
     */
    public ?int $selPosition = null;

    public ?string $selStart = null;

    public ?string $selEnd = null;

    /** Kliknięty pasek rezerwacji (popover z akcjami). */
    public ?int $selReservation = null;

    /** Dzień listy stanowisk w widoku telefonu. */
    public ?string $phoneDay = null;

    private ?ReservationCalendar $reservationCalendar = null;

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

        $this->layer = $this->layer()->value;

        if ($this->layer() === CalendarLayer::Reservations) {
            $this->startReservationsLayer();

            return;
        }

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

    /** Przełączenie warstwy zaczyna ją od jej własnego punktu startu. */
    public function updatedLayer(): void
    {
        $this->layer = $this->layer()->value;
        $this->clearSelection();

        if ($this->layer() === CalendarLayer::Reservations) {
            $this->startReservationsLayer();

            return;
        }

        $this->window = CalendarWindow::Month->value;
        $anchor = $this->calendar()->anchor();
        $seasons = $this->calendar()->seasons();
        $this->seasonId = $seasons === [] ? null : (int) $seasons[0]->id;
        $this->windowStart = $anchor instanceof CarbonImmutable ? $this->unit()->startFor($anchor)->toDateString() : null;
    }

    /** Warstwa „Rezerwacje" startuje od bieżącego tygodnia, w oknie dwóch tygodni. */
    private function startReservationsLayer(): void
    {
        $this->window = CalendarWindow::TwoWeeks->value;
        $today = CarbonImmutable::now($this->fishery()->timezoneName())->startOfDay();
        $this->windowStart = $this->unit()->startFor($today)->toDateString();
        $this->phoneDay = $today->toDateString();
    }

    /** Przełączenie jednostki trzyma się tej samej zasady co zmiana sezonu. */
    public function updatedWindow(): void
    {
        $this->clearSelection();

        if ($this->windowStart !== null) {
            $this->windowStart = $this->unit()->startFor($this->currentStart())->toDateString();
        }
    }

    public function previousWindow(): void
    {
        $this->clearSelection();

        if ($this->canGoBack()) {
            $this->windowStart = $this->unit()->previous($this->currentStart())->toDateString();
        }
    }

    public function nextWindow(): void
    {
        $this->clearSelection();

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
        // Warstwa „Rezerwacje" nie jest przypięta do sezonu — rezerwacja bywa wyjątkiem poza nim.
        if ($this->layer() === CalendarLayer::Reservations) {
            return true;
        }

        $season = $this->season();

        if ($season === null) {
            return false;
        }

        return $this->unit()->previous($this->currentStart())
            ->gte($this->unit()->startFor($this->asLocalDay($season->starts_on)));
    }

    public function canGoForward(): bool
    {
        if ($this->layer() === CalendarLayer::Reservations) {
            return true;
        }

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
            SaleUnavailabilityReason::Occupied => __('booked'),
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
        $unit = CalendarWindow::tryFrom($this->window) ?? CalendarWindow::Month;

        // Miesiąc jest wyłącznie w warstwie „Oferta" (042, Q7).
        return $unit === CalendarWindow::Month && $this->layer() === CalendarLayer::Reservations
            ? CalendarWindow::TwoWeeks
            : $unit;
    }

    public function layer(): CalendarLayer
    {
        return CalendarLayer::tryFrom($this->layer) ?? CalendarLayer::Reservations;
    }

    /**
     * ⚠️ **`windowStart` przychodzi OD KLIENTA**, więc nie wolno jej podać wprost parserowi dat:
     * `$wire.set('windowStart', 'x')` rzuciłoby `InvalidFormatException` i wywróciło cały ekran.
     * Kształt sprawdzamy wzorcem, a przy czymkolwiek innym wracamy do kotwicy — podgląd ma się
     * wtedy pokazać, a nie wysypać.
     */
    public function currentStart(): CarbonImmutable
    {
        $timezone = $this->fishery()->timezoneName();

        if (is_string($this->windowStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->windowStart) === 1) {
            try {
                return CarbonImmutable::parse($this->windowStart, $timezone)->startOfDay();
            } catch (\Throwable) {
                // Wzorzec przepuszcza „2026-13-45"; wtedy też schodzimy do kotwicy.
            }
        }

        return $this->calendar()->anchor() ?? CarbonImmutable::now($timezone)->startOfDay();
    }

    // ─── Warstwa „Rezerwacje" (zadanie 042) ──────────────────────────────────────────────

    public function reservationCalendar(): ReservationCalendar
    {
        return $this->reservationCalendar ??= new ReservationCalendar($this->fishery());
    }

    public function getReservationGrid(): ?ReservationCalendarGrid
    {
        if ($this->windowStart === null || $this->calendar()->missingSetup() !== null) {
            return null;
        }

        $days = $this->unit() === CalendarWindow::Week ? 7 : 14;
        $status = in_array($this->statusFilter, ['pending', 'confirmed', 'paid'], true) ? $this->statusFilter : null;

        return $this->reservationCalendar()->grid($this->currentStart(), $days, $this->groupId ?: null, $status);
    }

    /** Grupy stanowisk do filtra — tylko tego łowiska. @return array<int, string> */
    public function groupOptions(): array
    {
        return $this->fishery()->positionGroups()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Klik w wolną dobę: pierwszy zaznacza, drugi w tym samym wierszu wydłuża zakres — ale nigdy
     * przez dobę zajętą (zatrzymuje się przed nią). Przeszłość i doby zajęte są nieklikalne.
     */
    public function selectCell(int $positionId, string $date): void
    {
        $this->selReservation = null;
        $grid = $this->getReservationGrid();
        $row = $grid?->row($positionId);
        $day = $this->parseDay($date);
        $cell = $day === null ? null : $row?->cellOn($day);

        if ($row === null || $cell === null || ! $cell->isSelectable()) {
            return;
        }

        $start = $this->parseDay($this->selStart);

        if ($this->selPosition !== $positionId || $start === null || $day <= $start) {
            $this->selPosition = $positionId;
            $this->selStart = $this->selEnd = $day->toDateString();

            return;
        }

        $end = $start;

        for ($next = $start->addDay(); $next <= $day; $next = $next->addDay()) {
            $nextCell = $row->cellOn($next);

            if ($nextCell === null || ! $nextCell->isSelectable()) {
                break;
            }

            $end = $next;
        }

        $this->selEnd = $end->toDateString();
    }

    public function selectReservation(int $reservationId): void
    {
        $this->selPosition = $this->selStart = $this->selEnd = null;
        $this->selReservation = $this->scopedReservation($reservationId)?->getKey();
    }

    public function clearSelection(): void
    {
        $this->selPosition = $this->selStart = $this->selEnd = $this->selReservation = null;
    }

    public function selectPhoneDay(string $date): void
    {
        $day = $this->parseDay($date);
        $this->phoneDay = $day?->toDateString();
    }

    /** Zaznaczony zakres: stanowisko, przyjazd, wyjazd, liczba dób i odpowiedź warstwy oferty. */
    public function selection(): ?array
    {
        $start = $this->parseDay($this->selStart);
        $end = $this->parseDay($this->selEnd);
        $position = $this->selPosition === null ? null : $this->fishery()->positions()->whereKey($this->selPosition)->first();

        if ($start === null || $end === null || $position === null || $end < $start) {
            return null;
        }

        $position->setRelation('fishery', $this->fishery());
        $nights = (int) $start->diffInDays($end) + 1;
        $calendar = new FishingDayCalendar($this->fishery());
        $verdict = (new StayOffer($position))->offer($start, $nights);

        return [
            'position' => $position,
            'from' => $start->toDateString(),
            'nights' => $nights,
            'arrival' => $calendar->dayStartingOn($start)?->startsAt,
            'departure' => $calendar->dayStartingOn($end)?->endsAt,
            'price' => $verdict->available ? AmountFormatter::cents((int) $verdict->breakdown?->totalInCents(), $this->fishery()->currency?->name) : null,
            'refusal' => $verdict->available ? null : $verdict->reason?->label(),
        ];
    }

    public function selectedReservation(): ?Reservation
    {
        return $this->selReservation === null ? null : $this->scopedReservation($this->selReservation);
    }

    /** Adres pełnego formularza (C) z parametrami zaznaczenia. */
    public function fullFormUrl(int $positionId, string $from, int $nights): string
    {
        return ReservationResource::getUrl('create', [
            'fishery' => $this->fishery()->getKey(),
            'position' => $positionId,
            'from' => $from,
            'nights' => $nights,
        ]);
    }

    public function quickBookAction(): Action
    {
        return Action::make('quickBook')
            ->label(__('Book…'))
            ->icon('heroicon-m-plus')
            ->modalHeading(__('New reservation'))
            ->modalWidth('lg')
            ->visible(fn (): bool => Gate::allows('create', Reservation::class))
            ->fillForm(fn (array $arguments): array => $this->quickBookDefaults($arguments))
            ->schema(fn (array $arguments): array => [
                Hidden::make('position_id'),
                Hidden::make('starts_on'),
                TextInput::make('angler_name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('angler_phone')->label(__('Phone'))->tel()->maxLength(32),
                TextInput::make('angler_email')->label(__('Email (optional)'))->email()->maxLength(255),
                Grid::make(3)->schema([
                    TextInput::make('nights')->label(__('Nights'))->integer()->required()->minValue(1)
                        ->maxValue(fn (): int => max(1, $this->quickBookDefaults($arguments)['max_nights'])),
                    TextInput::make('anglers')->label(__('Anglers'))->integer()->required()->minValue(1)->maxValue(50),
                    TextInput::make('companions')->label(__('Companions'))->integer()->minValue(0)->maxValue(50),
                ]),
                TextInput::make('exception_reason')
                    ->label(__('Reason to book against the sale rules'))
                    ->helperText(__('Needed only when the offer is refused — the exception is recorded with your name.'))
                    ->maxLength(500),
            ])
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('fullForm')
                    ->label(__('Full form →'))
                    ->color('gray')
                    ->url(fn (): string => $this->fullFormUrl(
                        (int) $this->quickBookDefaults($arguments)['position_id'],
                        (string) $this->quickBookDefaults($arguments)['starts_on'],
                        (int) $this->quickBookDefaults($arguments)['nights'],
                    )),
            ])
            ->action(function (array $data, Action $action): void {
                Gate::authorize('create', Reservation::class);
                $outcome = ReservationFormSubmission::book($this->fishery(), $data);

                if (! $outcome->succeeded) {
                    Notification::make()->danger()->title(__('The reservation was not saved'))->body($outcome->message())->send();
                    $action->halt();
                }

                $this->clearSelection();
                $this->reservationCalendar = null;
                Notification::make()->success()->title(__('The reservation was saved'))->body(CreateReservation::requestNotice($outcome->reservation))->send();
            });
    }

    /**
     * Blokada zaznaczonych dób jednego stanowiska — skrót do zasobu blokad (zadanie 042).
     *
     * ⚠️ Okno blokady obejmuje całe dni, więc przecina też dobę zaczętą dzień wcześniej. Kolizja
     * z rezerwacją wymaga jawnej decyzji (G8, ADR-027) — skrót jej nie podejmuje, tylko odsyła do
     * ekranu blokad; mechanizm decyzji to zadanie 044.
     */
    public function quickBlockAction(): Action
    {
        return Action::make('quickBlock')
            ->label(__('Block…'))
            ->icon('heroicon-m-no-symbol')
            ->color('gray')
            ->modalHeading(__('Block sale'))
            ->visible(fn (): bool => Gate::allows('create', AvailabilityBlock::class))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->rows(2)->maxLength(1000),
                Toggle::make('reason_visible')->label(__('Reason visible to anglers'))->default(true),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                Gate::authorize('create', AvailabilityBlock::class);
                $from = $this->parseDay($arguments['from'] ?? null);
                $to = $this->parseDay($arguments['to'] ?? null);
                // ⚠️ Stanowisko z argumentu — wyłącznie z TEGO łowiska.
                $position = $this->fishery()->positions()->whereKey($arguments['position'] ?? null)->first();
                abort_if($from === null || $to === null || $to < $from || $position === null, 404);

                $outcome = AvailabilityBlockWriter::blockPosition(
                    $this->fishery(),
                    $position,
                    $from->toDateString(),
                    $to->toDateString(),
                    (string) $data['reason'],
                    (bool) ($data['reason_visible'] ?? true),
                );

                if ($outcome !== null) {
                    Notification::make()->danger()->title(__('The block was not saved'))->body($outcome)->send();
                    $action->halt();
                }

                $this->clearSelection();
                $this->reservationCalendar = null;
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    public function editReservationAction(): Action
    {
        return ReservationActions::edit()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
    }

    public function confirmReservationAction(): Action
    {
        return ReservationActions::confirm()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
    }

    public function markPaidAction(): Action
    {
        return ReservationActions::markPaid()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
    }

    public function markContactedAction(): Action
    {
        return ReservationActions::markContacted()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
    }

    public function cancelReservationAction(): Action
    {
        return ReservationActions::cancel()
            ->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments))
            ->after(fn () => $this->clearSelection());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function reservationFor(array $arguments): ?Reservation
    {
        $id = $arguments['reservation'] ?? $this->selReservation;

        return is_numeric($id) ? $this->scopedReservation((int) $id) : null;
    }

    /**
     * ⚠️ Id przychodzi od klienta — wyłącznie rezerwacja TEGO łowiska.
     */
    private function scopedReservation(int $id): ?Reservation
    {
        return $this->fishery()->reservations()->with(ReservationResource::eagerLoads())->whereKey($id)->first();
    }

    /**
     * Wartości krótkiego formularza z ARGUMENTÓW akcji (`position`, `date`, `nights`) — popover i widok
     * telefonu podają je jawnie, więc formularz nie zależy od zaznaczenia, które klik poza siatką
     * (np. w modalu) czyści. Długość nie przekracza wolnych dób (`ReservationCalendar::freeNightsFrom()`).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{position_id: int|null, starts_on: string|null, nights: int, max_nights: int, anglers: int, companions: int}
     */
    private function quickBookDefaults(array $arguments): array
    {
        $positionId = is_numeric($arguments['position'] ?? null) ? (int) $arguments['position'] : null;
        $day = $this->parseDay($arguments['date'] ?? null);
        $position = $positionId === null ? null : $this->fishery()->positions()->whereKey($positionId)->first();
        $max = ReservationCalendar::MAX_FREE_NIGHTS;

        if ($position !== null && $day !== null) {
            $position->setRelation('fishery', $this->fishery());
            $max = max(1, $this->reservationCalendar()->freeNightsFrom($position, $day));
        }

        $nights = is_numeric($arguments['nights'] ?? null) ? (int) $arguments['nights'] : 1;

        return [
            'position_id' => $position?->getKey(),
            'starts_on' => $day?->toDateString(),
            'nights' => min($nights, $max),
            'max_nights' => $max,
            'anglers' => 1,
            'companions' => 0,
        ];
    }

    private function parseDay(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date, $this->fishery()->timezoneName())->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
        $timezone = $this->fishery()->timezoneName();

        return CarbonImmutable::parse((string) $date->toDateString(), $timezone)->startOfDay();
    }
}
