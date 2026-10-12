<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\CalendarLayer;
use App\Enums\CalendarWindow;
use App\Enums\SaleUnavailabilityReason;
use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Filament\Resources\ReservationResource\ReservationActions;
use App\Filament\Resources\ReservationResource\ReservationFormQuote;
use App\Filament\Resources\ReservationResource\ReservationFormSchema;
use App\Filament\Resources\ReservationResource\ReservationFormSubmission;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
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
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

/**
 * Kalendarz łowiska — mechanizm G4: JEDNA siatka, dwie warstwy (zadania 019, 042).
 *
 * - **„Oferta"** (`?layer=offer`) — podgląd konfiguracji sprzedaży „co z tego wynika" (019).
 *   ⚠️ **Ta warstwa jest WYŁĄCZNIE podglądem.** Nie ma z niej edycji ani skrótów „napraw to"
 *   i niczego nie zapisuje. Kliknięcie prowadzi co najwyżej do właściwego ekranu konfiguracji.
 * - **„Rezerwacje"** (domyślna) — warstwa operacyjna (§3/§3a makiety etapu 3): paski rezerwacji
 *   i blokad na stanowiskach, oferta drobnym drukiem w pustych dobach, klik w dzień → zaznaczenie
 *   (przyjazd, potem wyjazd — `selectCell()`) i popover-WARSTWA nad siatką (zakotwiczona przy zaznaczeniu;
 *   klik w dzień przyjazdu albo ponownie w ten sam pasek zamyka), krótki formularz
 *   rezerwacji, blokada (przy kolizji → ekran decyzji E), akcje na rezerwacji (modale z
 *   `ReservationActions`). Siatkę składa `ReservationCalendar`; zapis idzie wyłącznie przez
 *   `ReservationBooking`.
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

    /**
     * Filtry warstwy „Rezerwacje": grupa stanowisk i filtr pasków (`ReservationCalendar::FILTERS` — status
     * z listy, liczniki nad siatką, „wymagają reakcji"). Filtr przygasza niepasujące paski.
     */
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

    /** Kliknięty pasek blokady (popover: powód, okres, edycja, usunięcie). */
    public ?int $selBlock = null;

    /** Dzień listy stanowisk w widoku telefonu. */
    public ?string $phoneDay = null;

    private ?ReservationCalendar $reservationCalendar = null;

    /**
     * Siatka warstwy „Rezerwacje" zapamiętana na czas JEDNEGO żądania, z kluczem ze stanu, z którego powstała —
     * klik w dzień liczyłby ją inaczej dwa razy (`selectCell()` i render). Nie jest to bufor werdyktu
     * (`dostepnosc.md` §2): ginie razem z żądaniem, a zapis zeruje ją przez `forgetReservationLayer()`.
     *
     * @var array{string, ?ReservationCalendarGrid}|null
     */
    private ?array $reservationGrid = null;

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

        // Bez `?layer=` w adresie wracamy do warstwy, na której użytkownik był ostatnio w tym łowisku.
        $remembered = $this->rememberedState();

        if (! request()->has('layer') && is_string($remembered['layer'] ?? null)) {
            $this->layer = $remembered['layer'];
        }

        $this->layer = $this->layer()->value;

        if ($this->layer() === CalendarLayer::Reservations) {
            $this->startReservationsLayer();
            $this->restoreWindow();

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

        $this->restoreWindow();
    }

    /**
     * Ostatnie miejsce w kalendarzu (warstwa, okno, początek okna, sezon) — w sesji, osobno dla łowiska
     * i warstwy, żeby powrót z innej zakładki trafiał tam, gdzie użytkownik skończył.
     */
    public function dehydrate(): void
    {
        if (! $this->record instanceof Fishery) {
            return;
        }

        $key = $this->stateKey();
        $state = (array) session()->get($key, []);
        $state['layer'] = $this->layer()->value;
        $state[$this->layer()->value] = [
            'window' => $this->window,
            'windowStart' => $this->windowStart,
            'seasonId' => $this->seasonId,
        ];
        session()->put($key, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function rememberedState(): array
    {
        return (array) session()->get($this->stateKey(), []);
    }

    private function stateKey(): string
    {
        return 'calendar.'.$this->fishery()->getKey();
    }

    /**
     * ⚠️ Stan z sesji traktujemy jak dane od klienta: okno tylko z listy warstwy, data tylko w kształcie
     * ISO (resztę i tak sprawdza `currentStart()`), sezon tylko spośród sezonów łowiska.
     */
    private function restoreWindow(): void
    {
        $saved = $this->rememberedState()[$this->layer()->value] ?? null;

        if (! is_array($saved)) {
            return;
        }

        if (is_string($saved['window'] ?? null) && array_key_exists($saved['window'], CalendarWindow::optionsFor($this->layer()))) {
            $this->window = $saved['window'];
        }

        if ($this->layer() === CalendarLayer::Offer && is_numeric($saved['seasonId'] ?? null)) {
            foreach ($this->calendar()->seasons() as $period) {
                if ((int) $period->id === (int) $saved['seasonId']) {
                    $this->seasonId = (int) $period->id;
                }
            }
        }

        if (is_string($saved['windowStart'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $saved['windowStart']) === 1) {
            $this->windowStart = $saved['windowStart'];
            $this->windowStart = $this->unit()->startFor($this->currentStart())->toDateString();
        }

        if ($this->layer() === CalendarLayer::Reservations) {
            $start = $this->currentStart();
            $today = CarbonImmutable::now($this->fishery()->timezoneName())->startOfDay();
            $inWindow = $today >= $start && $today <= $this->unit()->endFor($start);
            $this->phoneDay = ($inWindow ? $today : $start)->toDateString();
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
            $this->restoreWindow();

            return;
        }

        $this->window = CalendarWindow::Month->value;
        $anchor = $this->calendar()->anchor();
        $seasons = $this->calendar()->seasons();
        $this->seasonId = $seasons === [] ? null : (int) $seasons[0]->id;
        $this->windowStart = $anchor instanceof CarbonImmutable ? $this->unit()->startFor($anchor)->toDateString() : null;
        $this->restoreWindow();
    }

    /** Warstwa „Rezerwacje" startuje od bieżącego tygodnia, w oknie dwóch tygodni (tydzień do wyboru). */
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

    /** „Dziś" — okno zawierające bieżący dzień (warstwa „Rezerwacje"). */
    public function goToToday(): void
    {
        $this->clearSelection();
        $today = CarbonImmutable::now($this->fishery()->timezoneName())->startOfDay();
        $this->windowStart = $this->unit()->startFor($today)->toDateString();
        $this->phoneDay = $today->toDateString();
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
        $filter = in_array($this->statusFilter, ReservationCalendar::FILTERS, true) ? $this->statusFilter : null;
        $key = implode('|', [$this->currentStart()->toDateString(), $days, (int) $this->groupId, (string) $filter]);

        if ($this->reservationGrid !== null && $this->reservationGrid[0] === $key) {
            return $this->reservationGrid[1];
        }

        // Oferta w pustych dobach (047, A3) — ta sama siatka `SaleCalendar` co warstwa „Oferta", to samo okno.
        $offer = $this->calendar()->grid(from: $this->currentStart(), unit: $this->unit());
        $grid = $this->reservationCalendar()->grid($this->currentStart(), $days, $this->groupId ?: null, $filter, offer: $offer);
        $this->reservationGrid = [$key, $grid];

        return $grid;
    }

    /** Po zapisie (rezerwacja, blokada) warstwa liczy się od nowa — razem z zapamiętaną siatką. */
    private function forgetReservationLayer(): void
    {
        $this->reservationCalendar = null;
        $this->reservationGrid = null;
    }

    /** Licznik nad siatką jako filtr — drugi klik zdejmuje filtr (047, A8). */
    public function toggleFilter(string $filter): void
    {
        $this->clearSelection();
        $this->statusFilter = $this->statusFilter === $filter || ! in_array($filter, ReservationCalendar::FILTERS, true) ? null : $filter;
    }

    /** Grupy stanowisk do filtra — tylko tego łowiska. @return array<int, string> */
    public function groupOptions(): array
    {
        return $this->fishery()->positionGroups()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Klik w dzień (model połówek): pierwszy klik wskazuje PRZYJAZD (doba od tego dnia musi być wolna),
     * klik w późniejszy dzień tego wiersza wskazuje WYJAZD — zakres dób kończy się przed pierwszą zajętą.
     * Klik we wcześniejszy dzień przesuwa przyjazd, o ile doby do dotychczasowego są wolne. Ponowny klik
     * w dzień PRZYJAZDU zamyka zaznaczenie. Wyjątek: klik w bieżący dzień wyjazdu przedłuża pobyt o dobę
     * (działa jak klik w dzień następny); po pierwszym kliku wyjazdem jest już dzień tuż po przyjeździe.
     * `selStart`/`selEnd` to pierwsza i OSTATNIA doba.
     */
    public function selectCell(int $positionId, string $date): void
    {
        $this->selReservation = $this->selBlock = null;
        $grid = $this->getReservationGrid();
        $row = $grid?->row($positionId);
        $day = $this->parseDay($date);
        $cell = $day === null ? null : $row?->cellOn($day);

        if ($row === null || $day === null || $cell === null || $cell->past) {
            return;
        }

        $start = $this->parseDay($this->selStart);
        $end = $this->parseDay($this->selEnd);
        $sameRow = $this->selPosition === $positionId && $start !== null && $end !== null;

        if ($sameRow && $day->equalTo($start)) {
            $this->clearSelection();

            return;
        }

        if ($sameRow && $day > $start) {
            // Klik w BIEŻĄCY dzień wyjazdu przedłuża pobyt o dobę (działa jak klik w dzień następny) —
            // inaczej nie dawałby żadnego efektu. Po pierwszym kliku wyjazdem jest dzień tuż po przyjeździe.
            if ($day->equalTo($end->addDay())) {
                $day = $day->addDay();
            }

            $last = $start;

            for ($night = $start->addDay(); $night < $day; $night = $night->addDay()) {
                if (! ($row->cellOn($night)?->isSelectable() ?? false)) {
                    break;
                }

                $last = $night;
            }

            $this->selEnd = $last->toDateString();

            return;
        }

        if (! $cell->isSelectable()) {
            return;
        }

        if ($sameRow && $day < $start) {
            $first = $start;

            for ($night = $start->subDay(); $night >= $day; $night = $night->subDay()) {
                if (! ($row->cellOn($night)?->isSelectable() ?? false)) {
                    break;
                }

                $first = $night;
            }

            $this->selStart = $first->toDateString();

            return;
        }

        $this->selPosition = $positionId;
        $this->selStart = $this->selEnd = $day->toDateString();
    }

    /** Klik w pasek rezerwacji; ponowny klik w ten sam pasek zamyka popover. */
    public function selectReservation(int $reservationId): void
    {
        $toggle = $this->selReservation === $reservationId;
        $this->clearSelection();
        $this->selReservation = $toggle ? null : $this->scopedReservation($reservationId)?->getKey();
    }

    /** Klik w pasek blokady (047, A6) — wyłącznie blokada TEGO łowiska. */
    public function selectBlock(int $blockId): void
    {
        $toggle = $this->selBlock === $blockId;
        $this->clearSelection();
        $this->selBlock = $toggle ? null : $this->scopedBlock($blockId)?->getKey();
    }

    public function clearSelection(): void
    {
        $this->selPosition = $this->selStart = $this->selEnd = $this->selReservation = $this->selBlock = null;
    }

    public function selectPhoneDay(string $date): void
    {
        $day = $this->parseDay($date);
        $this->phoneDay = $day?->toDateString();
    }

    /**
     * Zaznaczony zakres: stanowisko, przyjazd, wyjazd, liczba dób, odpowiedź warstwy oferty i ile dób
     * da się wydłużyć.
     *
     * @return array{position: Position, from: string, to: string, nights: int, arrival: ?CarbonImmutable, departure: ?CarbonImmutable, price: ?string, refusal: ?string, max_nights: int}|null
     */
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
        $quote = $this->quoteFor($position, $start, $nights);

        return [
            'position' => $position,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'nights' => $nights,
            'arrival' => $quote['arrival'],
            'departure' => $quote['departure'],
            'price' => $quote['price'],
            'refusal' => $quote['refusal'],
            'max_nights' => $this->reservationCalendar()->freeNightsFrom($position, $start),
        ];
    }

    /**
     * Orientacyjna cena pobytu dla arkusza telefonu (047, A10) — bez przerysowania strony.
     *
     * @return array{price: ?string, refusal: ?string, arrival: ?string, departure: ?string}
     */
    #[Renderless]
    public function quote(int $positionId, string $date, int $nights): array
    {
        $position = $this->fishery()->positions()->whereKey($positionId)->first();
        $day = $this->parseDay($date);

        if ($position === null || $day === null) {
            return ['price' => null, 'refusal' => null, 'arrival' => null, 'departure' => null];
        }

        $position->setRelation('fishery', $this->fishery());
        $quote = $this->quoteFor($position, $day, $this->clamp($nights, 1, ReservationCalendar::MAX_FREE_NIGHTS));
        $timezone = $this->fishery()->timezoneName();

        return [
            'price' => $quote['price'],
            'refusal' => $quote['refusal'],
            'arrival' => $quote['arrival']?->setTimezone($timezone)->format('d.m H:i'),
            'departure' => $quote['departure']?->setTimezone($timezone)->format('d.m H:i'),
        ];
    }

    /**
     * @return array{price: ?string, refusal: ?string, arrival: ?CarbonImmutable, departure: ?CarbonImmutable}
     */
    private function quoteFor(Position $position, CarbonImmutable $start, int $nights): array
    {
        $calendar = new FishingDayCalendar($this->fishery());
        $form = ReservationFormQuote::for($this->fishery(), [
            'position_id' => $position->getKey(),
            'starts_on' => $start->toDateString(),
            'nights' => $nights,
            'anglers' => 1,
            'companions' => 0,
        ]);

        return [
            'arrival' => $calendar->dayStartingOn($start)?->startsAt,
            'departure' => $calendar->dayStartingOn($start->addDays($nights - 1))?->endsAt,
            'price' => $form?->totalLabel(),
            'refusal' => $form !== null && $form->refused() ? implode(' ', $form->refusalLines) : null,
        ];
    }

    public function selectedReservation(): ?Reservation
    {
        return $this->selReservation === null ? null : $this->scopedReservation($this->selReservation);
    }

    public function selectedBlock(): ?AvailabilityBlock
    {
        return $this->selBlock === null ? null : $this->scopedBlock($this->selBlock);
    }

    /** Adres edycji blokady (zasób blokad) — z popovera paska blokady. */
    public function blockEditUrl(AvailabilityBlock $block): string
    {
        return AvailabilityBlockResource::getUrl('edit', ['record' => $block, 'fishery' => $this->fishery()->getKey()]);
    }

    /** „Nowa rezerwacja" nad siatką — pełny formularz C. */
    public function newReservationUrl(): string
    {
        return ReservationResource::getUrl('create', ['fishery' => $this->fishery()->getKey()]);
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

    /**
     * Krótki formularz „Zarezerwuj…" — `ReservationFormSchema::quick()`, ten sam co szybka edycja (047, C1):
     * cena z rozbiciem, pole powodu tylko przy odmowie oferty, wyjątek usługi niepotrzebny (brak pola usług).
     */
    public function quickBookAction(): Action
    {
        return Action::make('quickBook')
            ->label(__('Book…'))
            ->icon('heroicon-m-plus')
            ->modalHeading(fn (array $arguments): string => $this->quickBookHeading($arguments))
            ->modalWidth('lg')
            ->visible(fn (): bool => Gate::allows('create', Reservation::class))
            ->fillForm(fn (array $arguments): array => $this->quickBookDefaults($arguments))
            ->schema(fn (array $arguments): array => ReservationFormSchema::quick(
                fn (): Fishery => $this->fishery(),
                maxNights: $this->quickBookDefaults($arguments)['max_nights'],
            ))
            // „Pełny formularz →" zapisuje rezerwację z wpisanych danych i przechodzi do jej pełnej edycji —
            // nic z krótkiego formularza nie przepada.
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndEdit', ['full' => true])->label(__('Save and open full form →')),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                Gate::authorize('create', Reservation::class);
                $outcome = ReservationFormSubmission::book($this->fishery(), $data);

                if (! $outcome->succeeded) {
                    Notification::make()->danger()->title(__('The reservation was not saved'))->body($outcome->message())->send();
                    $action->halt();
                }

                $this->clearSelection();
                $this->forgetReservationLayer();
                Notification::make()->success()->title(__('The reservation was saved'))->body(CreateReservation::requestNotice($outcome->reservation))->send();

                if (! empty($arguments['full']) && $outcome->reservation !== null) {
                    $this->redirect(ReservationResource::getUrl('edit', ['record' => $outcome->reservation]));
                }
            });
    }

    /**
     * Blokada zaznaczonych dób jednego stanowiska — skrót do zasobu blokad (zadania 042, 047 A9).
     *
     * ⚠️ Okno blokady to doby od „od" do „do" (`FishingDayCalendar::nightsWindow()`, przełom dnia o godzinie
     * doby), więc rezerwacja kończąca się w dniu „od" z nią nie koliduje. Kolizja
     * z rezerwacją wymaga jawnej decyzji (G8, ADR-027): skrót pyta `CollisionGate` i przy kolizji
     * przechodzi na ekran decyzji E — blokada zapisze się tam razem z decyzjami.
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
                    $this->redirect($outcome);

                    return;
                }

                $this->clearSelection();
                $this->forgetReservationLayer();
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    /** „Usuń blokadę…" z popovera paska blokady — polityka blokady jawnie (047, A6). */
    public function deleteBlockAction(): Action
    {
        return Action::make('deleteBlock')
            ->label(__('Delete block…'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete the block'))
            ->modalDescription(__('Sale on these nights opens again. Reservations kept at this block stay as they are.'))
            ->visible(fn (array $arguments): bool => ($block = $this->scopedBlock($arguments['block'] ?? null)) !== null && Gate::allows('delete', $block))
            ->action(function (array $arguments): void {
                $block = $this->scopedBlock($arguments['block'] ?? null);
                abort_if($block === null, 404);
                Gate::authorize('delete', $block);
                $block->delete();
                $this->clearSelection();
                $this->forgetReservationLayer();
                Notification::make()->success()->title(__('The block was deleted'))->send();
            });
    }

    public function quickEditReservationAction(): Action
    {
        return ReservationActions::quickEdit()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
    }

    public function fullEditReservationAction(): Action
    {
        return ReservationActions::fullEdit()->record(fn (array $arguments): ?Reservation => $this->reservationFor($arguments));
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

    /** ⚠️ Id przychodzi od klienta — wyłącznie blokada sprzedaży TEGO łowiska. */
    private function scopedBlock(mixed $id): ?AvailabilityBlock
    {
        return is_numeric($id)
            ? $this->fishery()->availabilityBlocks()->with('positions')->whereKey((int) $id)->first()
            : null;
    }

    /**
     * Nagłówek krótkiego formularza: „Rezerwacja stanowiska: X" (bez stanowiska — „Nowa rezerwacja").
     *
     * @param  array<string, mixed>  $arguments
     */
    private function quickBookHeading(array $arguments): string
    {
        $defaults = $this->quickBookDefaults($arguments);
        $position = $defaults['position_id'] === null ? null : $this->fishery()->positions()->whereKey($defaults['position_id'])->first();

        // Stanowisko w samym nagłówku („Rezerwacja stanowiska: 7") — sama nazwa pod nagłówkiem bywa gołym
        // numerem i nic nie mówi. Termin z godzinami stoi obok liczby dób w formularzu.
        return $position === null
            ? __('New reservation')
            : __('Position reservation: :position', ['position' => $position->name]);
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
