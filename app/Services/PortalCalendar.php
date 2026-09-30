<?php

namespace App\Services;

use App\Enums\CalendarWindow;
use App\Enums\ParticipantRole;
use App\Enums\PositionAttributeType;
use App\Enums\PriceRuleKind;
use App\Enums\SaleUnavailabilityReason;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Kalendarz portalu wędkarza — płaska siatka stanowisk na jeden tydzień (zadanie 033, portal-v3 §3).
 *
 * ⚠️ **Komórki pochodzą WYŁĄCZNIE z `SaleCalendar`, a ten pyta warstwę oferty** (ADR-015, `cennik.md`
 * §5). Ta klasa czyta stan z adresu, wybiera stanowiska po filtrach, formatuje werdykty dla wędkarza
 * i składa pas pakietów z werdyktów „zacznij wcześniej". Żadnej reguły sprzedaży tu nie ma.
 *
 * ⚠️ **Stan jest w adresie** (ADR-022): tydzień, łowiący, grupa, cechy, na telefonie doba i stanowisko.
 * Nieznane albo błędne wartości spadają do domyślnych, nigdy do błędu. Nazwy parametrów zależą od
 * języka i mają jeden dom — `PortalRoutes::CALENDAR_PARAMS`.
 *
 * Stanowiska wycofane są ukryte (033, Rozstrzygnięcie 3); stanowisko o mniejszej pojemności niż
 * wybrana liczba łowiących dostaje komunikat na cały wiersz i nie jest pytane o werdykty.
 */
final class PortalCalendar
{
    /** @var array<string, string> */
    private array $state;

    private CarbonImmutable $week;

    private int $anglers;

    private ?string $group = null;

    /** @var list<string> */
    private array $features = [];

    private ?SaleCalendarGrid $grid = null;

    /**
     * @param  Fishery  $fishery  łowisko z relacjami z `PortalFisheryPage::load()` (stanowiska w sprzedaży,
     *                            ich grupy i wartości cech)
     * @param  array<string, mixed>  $query  zapytanie żądania
     */
    public function __construct(
        private readonly Fishery $fishery,
        array $query,
        private readonly string $locale,
    ) {
        $this->state = PortalRoutes::calendarStateFrom($query, $locale);
        $this->week = $this->resolveWeek($this->state['week'] ?? null);
        $this->anglers = $this->resolveAnglers($this->state['anglers'] ?? null);
        $this->group = $this->resolveGroup($this->state['group'] ?? null);
        $this->features = $this->resolveFeatures($this->state['features'] ?? null);
    }

    // ------------------------------------------------------------------ stan i adresy

    public function week(): CarbonImmutable
    {
        return $this->week;
    }

    /** @return array<int, CarbonImmutable> */
    public function days(): array
    {
        return array_map(fn (int $i): CarbonImmutable => $this->week->addDays($i), range(0, 6));
    }

    public function anglers(): int
    {
        return $this->anglers;
    }

    /**
     * Adres kalendarza z nadpisanym stanem — `null` usuwa parametr. Domyślne wartości nie trafiają
     * do adresu, żeby jeden widok nie miał wielu adresów.
     *
     * @param  array<string, string|int|null>  $overrides
     */
    public function url(array $overrides = []): string
    {
        $state = [
            'week' => $this->week->equalTo($this->defaultWeek()) ? null : $this->week->toDateString(),
            'anglers' => $this->anglers === 1 ? null : (string) $this->anglers,
            'group' => $this->group,
            'features' => $this->features === [] ? null : implode(',', $this->features),
            'day' => $this->state['day'] ?? null,
            'position' => $this->state['position'] ?? null,
        ];

        foreach ($overrides as $key => $value) {
            $state[$key] = $value === null ? null : (string) $value;
        }

        if (($state['week'] ?? null) === $this->defaultWeek()->toDateString()) {
            $state['week'] = null;
        }

        $query = PortalRoutes::calendarQueryFor(array_filter($state, fn (?string $v): bool => $v !== null), $this->locale);
        $base = (string) PortalRoutes::fisheryUrl($this->fishery, $this->locale);

        return $base.($query === [] ? '' : '?'.http_build_query($query)).'#'.PortalRoutes::CALENDAR_ANCHOR[$this->locale];
    }

    public function previousWeekUrl(): ?string
    {
        $previous = $this->week->subWeek();

        return $previous < CalendarWindow::Week->startFor($this->today()) ? null : $this->url(['week' => $previous->toDateString(), 'day' => null, 'position' => null]);
    }

    public function nextWeekUrl(): ?string
    {
        $next = $this->week->addWeek();
        $lastSeasonEnd = $this->fishery->salePeriods()->max('ends_on');

        if ($lastSeasonEnd !== null && $next > CarbonImmutable::parse((string) $lastSeasonEnd, $this->fishery->timezoneName())) {
            return null;
        }

        return $this->url(['week' => $next->toDateString(), 'day' => null, 'position' => null]);
    }

    // ------------------------------------------------------------------ przełączniki

    /**
     * Przełącznik łowiących: 1 … największa pojemność stanowiska w sprzedaży.
     *
     * @return list<array{count: int, url: string, active: bool}>
     */
    public function anglerOptions(): array
    {
        $max = max(1, (int) $this->positions()->max('max_anglers'));

        return array_map(fn (int $count): array => [
            'count' => $count,
            'url' => $this->url(['anglers' => $count === 1 ? null : $count]),
            'active' => $count === $this->anglers,
        ], range(1, $max));
    }

    /**
     * „Wszystkie" i grupy (jedna naraz). Puste, gdy łowisko nie ma ani grup, ani cech do filtrowania.
     *
     * ⚠️ **„Wszystkie" to RESET filtrów, nie „wszystkie grupy":** zawsze pokazuje liczbę WSZYSTKICH
     * stanowisk w sprzedaży, czyści naraz grupę i cechy i jest aktywne tylko bez żadnego filtra.
     * Wcześniej czyściło samą grupę, a licznik zależał od wybranych cech — przy włączonej cesze
     * kliknięcie „Wszystkie" niczego nie zmieniało (uwaga autora po 033).
     * Liczba przy GRUPIE = stanowiska tej grupy w bieżącym wyborze cech.
     *
     * @return list<array{label: string, count: int, url: string, active: bool}>
     */
    public function groupOptions(): array
    {
        $groups = $this->groups();

        if ($groups === [] && $this->featureCatalogue() === []) {
            return [];
        }

        $options = [[
            'label' => __('All'),
            'count' => $this->positions()->count(),
            'url' => $this->url(['group' => null, 'features' => null]),
            'active' => $this->group === null && $this->features === [],
        ]];

        foreach ($groups as $slug => $group) {
            $options[] = [
                'label' => $group->name,
                'count' => $this->matching($slug, $this->features)->count(),
                // Aktywna grupa kliknięta ponownie się wyłącza — tak samo jak cecha (uwaga autora po 033).
                'url' => $this->url(['group' => $this->group === $slug ? null : $slug]),
                'active' => $this->group === $slug,
            ];
        }

        return $options;
    }

    /**
     * Cechy filtrowalne (łączone przez I). Liczba = stanowiska w bieżącej grupie spełniające wybrane
     * cechy i tę. ⚠️ Trzeci stan (brak wartości) nie spełnia żadnej cechy.
     *
     * @return list<array{label: string, count: int, url: string, active: bool}>
     */
    public function featureOptions(): array
    {
        $options = [];

        foreach ($this->featureCatalogue() as $key => $label) {
            $active = in_array($key, $this->features, true);
            $toggled = $active
                ? array_values(array_diff($this->features, [$key]))
                : [...$this->features, $key];

            $options[] = [
                'label' => $label,
                'count' => $this->matching($this->group, $active ? $this->features : $toggled)->count(),
                'url' => $this->url(['features' => $toggled === [] ? null : implode(',', $toggled)]),
                'active' => $active,
            ];
        }

        return $options;
    }

    // ------------------------------------------------------------------ siatka

    /**
     * Wiersze siatki w naturalnej kolejności etykiet.
     *
     * @return list<array{position: Position, header: array<string, mixed>, cells: list<array<string, mixed>>, message: string|null}>
     */
    public function rows(): array
    {
        $visible = $this->matching($this->group, $this->features);
        $gridRows = [];

        foreach ($this->grid()->rows as $row) {
            $gridRows[(int) $row->position->id] = $row;
        }

        $rows = [];

        foreach ($visible->sort(fn (Position $a, Position $b): int => strnatcasecmp($a->name, $b->name)) as $position) {
            $gridRow = $gridRows[(int) $position->id] ?? null;

            $rows[] = [
                'position' => $position,
                'header' => $this->header($position, $gridRow),
                'message' => $gridRow === null
                    ? trans_choice('up to :count angler — not available for :anglers anglers|up to :count anglers — not available for :anglers anglers', (int) $position->max_anglers, [
                        'count' => (int) $position->max_anglers,
                        'anglers' => $this->anglers,
                    ])
                    : null,
                'cells' => $gridRow === null ? [] : array_map(fn (SaleCalendarCell $cell): array => $this->cell($cell, $gridRow), $gridRow->cells),
            ];
        }

        return $rows;
    }

    /**
     * Pas „Pakiety" — z werdyktów „zacznij wcześniej" widocznych wierszy (033, Rozstrzygnięcie 4).
     * Portal tylko czyta werdykty: pakiet to doby, które warstwa oferty kazała zacząć wcześniej,
     * razem z dobą początku.
     *
     * @return list<array{from: int, to: int, label: string}> indeksy dni tygodnia (0–6) i opis
     */
    public function packages(): array
    {
        $ranges = [];

        foreach ($this->grid()->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->startEarlierOn === null) {
                    continue;
                }

                $key = $cell->startEarlierOn->toDateString();
                $ranges[$key]['start'] = $cell->startEarlierOn;
                $ranges[$key]['last'] = max($ranges[$key]['last'] ?? $cell->night, $cell->night);
            }
        }

        $bands = [];
        $weekEnd = $this->week->addDays(6);

        foreach ($ranges as $range) {
            $start = $range['start'];
            $last = $range['last'];
            $from = $start < $this->week ? $this->week : $start;
            $exit = $last->addDay();

            $bands[] = [
                'from' => (int) $this->week->diffInDays($from),
                'to' => (int) $this->week->diffInDays(min($last, $weekEnd)),
                'label' => $start < $this->week
                    ? __('bundle from :start', ['start' => $this->dayLabel($start)])
                    : __('bundle :start – :end', ['start' => $this->dayLabel($start), 'end' => $this->dayLabel($exit)]),
            ];
        }

        usort($bands, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $bands;
    }

    /**
     * Notki nad siatką: ograniczenie albo usługa obowiązkowa obejmująca WIELE widocznych stanowisk
     * (§3.1) — jedna notka z zakresem stanowisk zamiast tego samego znacznika w każdym wierszu.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $blocks = [];
        $services = [];

        foreach ($this->grid()->rows as $row) {
            foreach ($row->suspensions as $block) {
                $blocks[(int) $block->id]['block'] = $block;
                $blocks[(int) $block->id]['labels'][] = $row->position->name;
            }

            foreach ($row->services as $status) {
                if ($status->isRequired) {
                    $services[(int) $status->service->id]['service'] = $status->service;
                    $services[(int) $status->service->id]['labels'][] = $row->position->name;
                }
            }
        }

        $notes = [];

        foreach ($blocks as $entry) {
            if (count($entry['labels']) > 1) {
                $notes[] = $this->positionsText($entry['labels']).': '.$this->suspensionText($entry['block']);
            }
        }

        foreach ($services as $entry) {
            if (count($entry['labels']) > 1) {
                $notes[] = $this->positionsText($entry['labels']).': + '.$entry['service']->priceLabelForVisitor($this->currency())
                    .' '.$entry['service']->name.' ('.__('mandatory').')';
            }
        }

        return $notes;
    }

    /**
     * Mobile: wybrana doba (parametr `doba`, domyślnie dziś w tym tygodniu albo poniedziałek).
     */
    public function selectedDay(): CarbonImmutable
    {
        $raw = $this->state['day'] ?? null;

        if ($raw !== null && ($day = $this->parseDate($raw)) !== null && $day >= $this->week && $day <= $this->week->addDays(6)) {
            return $day;
        }

        $today = $this->today();

        return $today >= $this->week && $today <= $this->week->addDays(6) ? $today : $this->week;
    }

    public function dayUrl(CarbonImmutable $day): string
    {
        return $this->url(['day' => $day->toDateString(), 'position' => null]);
    }

    public function positionUrl(Position $position): string
    {
        return $this->url(['day' => $this->selectedDay()->toDateString(), 'position' => $position->name]);
    }

    public function selectedPosition(): ?string
    {
        return $this->state['position'] ?? null;
    }

    /** Indeks wybranej doby w tygodniu (0–6) — do odczytu komórki wiersza na telefonie. */
    public function selectedDayIndex(): int
    {
        return (int) $this->week->diffInDays($this->selectedDay());
    }

    public function dayLabel(CarbonImmutable $day): string
    {
        return $day->locale($this->locale)->isoFormat('dd D.MM');
    }

    // ------------------------------------------------------------------ wnętrze

    /**
     * Siatka JEDNA na render (wiersze, pakiety, notki czytają tę samą) — wyłącznie dla stanowisk
     * widocznych po filtrach i mieszczących wybraną liczbę łowiących.
     */
    private function grid(): SaleCalendarGrid
    {
        if ($this->grid === null) {
            $ids = $this->matching($this->group, $this->features)
                ->filter(fn (Position $p): bool => $p->max_anglers === null || (int) $p->max_anglers >= $this->anglers)
                ->pluck('id')->all();

            $this->grid = (new SaleCalendar($this->fishery))
                ->grid($this->week, CalendarWindow::Week, $this->anglers, 0, null, $ids);
        }

        return $this->grid;
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Position $position, ?SaleCalendarRow $row): array
    {
        $required = [];
        $suspended = [];

        foreach ($row->services ?? [] as $status) {
            if ($status->isRequired) {
                $required[] = '+ '.$status->service->priceLabelForVisitor($this->currency()).' '.$status->service->name;
            }
        }

        foreach ($row->suspensions ?? [] as $block) {
            $suspended[] = $this->suspensionText($block);
        }

        $blocked = false;

        foreach ($row->cells ?? [] as $cell) {
            if ($cell->reason === SaleUnavailabilityReason::SaleBlocked) {
                $blocked = true;
            }
        }

        return [
            'label' => $position->name,
            'groups' => $position->groups->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->implode(', '),
            'capacity' => $position->max_anglers !== null ? __('up to :count', ['count' => $position->max_anglers]) : null,
            'features' => array_values(array_intersect_key($this->featureCatalogue(), array_flip($this->featuresOf($position)))),
            'restrictions' => $suspended,
            'services' => $required,
            'blocked' => $blocked,
        ];
    }

    /**
     * Komórka dla wędkarza — treść, stan i podpowiedź. Nic tu nie jest liczone: wszystko pochodzi
     * z werdyktu warstwy oferty niesionego przez `SaleCalendarCell`.
     *
     * @return array<string, mixed>
     */
    private function cell(SaleCalendarCell $cell, SaleCalendarRow $row): array
    {
        if ($cell->sellable && $cell->totalInCents !== null && $cell->nights !== null) {
            $surcharge = false;

            foreach ($cell->breakdown?->items() ?? [] as $item) {
                if ($item->kind === PriceRuleKind::Surcharge) {
                    $surcharge = true;
                }
            }

            return [
                'state' => 'sellable',
                'price' => AmountFormatter::forVisitor($cell->totalInCents, $this->currency()),
                'nights' => trans_choice(':count night|:count nights', $cell->nights, ['count' => $cell->nights]),
                'surcharge' => $surcharge,
                'tooltip' => $this->breakdown($cell, $row),
            ];
        }

        if ($cell->startEarlierOn !== null) {
            return [
                'state' => 'bundle',
                'text' => __('start :day', ['day' => $this->dayLabel($cell->startEarlierOn)]),
                'title' => __('This night is inside a bundle sold whole — it starts on :day.', ['day' => $this->dayLabel($cell->startEarlierOn)]),
            ];
        }

        return [
            'state' => 'refused',
            'text' => $this->refusalShort($cell),
            'title' => $cell->reason?->label() ?? '',
            'from_fishery' => $cell->reason === SaleUnavailabilityReason::SaleBlocked,
        ];
    }

    /**
     * Rozbicie ceny z warstwy oferty: stawka × doby, osoba towarzysząca (tylko płatna), dopłaty pod
     * nazwami łowiska, obniżka przedsprzedażowa, suma; usługi obowiązkowe POD sumą, osobno (§3.4).
     *
     * @return array{title: string, lines: list<array{label: string, amount: string}>, total: string, discount: string|null, services: list<string>}
     */
    private function breakdown(SaleCalendarCell $cell, SaleCalendarRow $row): array
    {
        $groups = [];

        foreach ($cell->breakdown?->items() ?? [] as $item) {
            if ($item->role === ParticipantRole::Companion && $item->amountInCents() === 0) {
                continue;
            }

            $label = match (true) {
                $item->kind === PriceRuleKind::Surcharge => (string) ($item->label ?: __('Surcharge')),
                $item->role === ParticipantRole::Companion => __('Companion'),
                default => __('Angler'),
            };

            $key = $label.'|'.$item->amountPerPersonInCents;
            $groups[$key]['label'] = $label;
            $groups[$key]['per'] = $item->amountPerPersonInCents;
            $groups[$key]['people'] = max($groups[$key]['people'] ?? 0, $item->people);
            $groups[$key]['nights'] = ($groups[$key]['nights'] ?? 0) + 1;
            $groups[$key]['total'] = ($groups[$key]['total'] ?? 0) + $item->amountInCents();
        }

        $lines = array_map(fn (array $g): array => [
            'label' => $g['label'].($g['people'] > 1 ? ' × '.$g['people'] : '').' × '.trans_choice(':count night|:count nights', $g['nights'], ['count' => $g['nights']]),
            'amount' => AmountFormatter::forVisitor($g['total'], $this->currency()),
        ], array_values($groups));

        $discount = (int) $cell->breakdown?->discountInCents();
        $services = [];

        foreach ($row->services as $status) {
            if ($status->isRequired) {
                $services[] = '+ '.$status->service->priceLabelForVisitor($this->currency()).' '.$status->service->name.' ('.__('mandatory').')';
            }
        }

        $last = $cell->night->addDays((int) $cell->nights);

        return [
            'title' => $this->dayLabel($cell->night).' → '.$this->dayLabel($last).' · '.__('pos. :label', ['label' => $row->position->name]),
            'lines' => $lines,
            'discount' => $discount > 0 ? '−'.AmountFormatter::forVisitor($discount, $this->currency()) : null,
            'total' => AmountFormatter::forVisitor((int) $cell->totalInCents, $this->currency()),
            'services' => $services,
        ];
    }

    /** Krótki powód dla wędkarza — pełny tekst ze słownika idzie do podpowiedzi. */
    private function refusalShort(SaleCalendarCell $cell): string
    {
        return match ($cell->reason) {
            SaleUnavailabilityReason::SaleBlocked => $cell->blockPublicReason ?? __('not available'),
            SaleUnavailabilityReason::BeyondSaleHorizon => __('not on sale yet'),
            SaleUnavailabilityReason::NoPriceDefined, SaleUnavailabilityReason::NoCompanionPrice => __('no price'),
            SaleUnavailabilityReason::StayTooLong, SaleUnavailabilityReason::StayTooShort,
            SaleUnavailabilityReason::WeekendBroken, SaleUnavailabilityReason::WholeTermBroken,
            SaleUnavailabilityReason::BelowPresaleMinimum => __('not available'),
            SaleUnavailabilityReason::PositionWithdrawn => __('withdrawn'),
            default => __('off season'),
        };
    }

    private function suspensionText(AvailabilityBlock $block): string
    {
        $text = $block->reason_visible && filled($block->reason)
            ? (string) $block->reason
            : __('no :feature', ['feature' => Str::lower((string) $block->attribute?->name)]);

        return $text.' — '.($block->ends_on === null
            ? __('until further notice')
            : __('until :date', ['date' => $block->ends_on->format('d.m')]));
    }

    /**
     * „St. 22–40" dla ciągłego zakresu etykiet liczbowych, inaczej lista.
     *
     * @param  list<string>  $labels
     */
    private function positionsText(array $labels): string
    {
        usort($labels, 'strnatcasecmp');

        return __('pos. :label', ['label' => count($labels) > 3
            ? $labels[0].'–'.$labels[count($labels) - 1]
            : implode(', ', $labels)]);
    }

    /**
     * Stanowiska w sprzedaży z wybranej grupy, spełniające wszystkie wybrane cechy.
     *
     * @param  list<string>  $features
     * @return Collection<int, Position>
     */
    private function matching(?string $group, array $features): Collection
    {
        return $this->positions()->filter(function (Position $position) use ($group, $features): bool {
            if ($group !== null && ! $position->groups->contains(fn (PositionGroup $g): bool => Str::slug($g->name) === $group)) {
                return false;
            }

            return array_diff($features, $this->featuresOf($position)) === [];
        })->values();
    }

    /** @return Collection<int, Position> */
    private function positions(): Collection
    {
        return $this->fishery->positions;
    }

    /** @return array<string, PositionGroup> slug → grupa, tylko grupy ze stanowiskiem w sprzedaży */
    private function groups(): array
    {
        $groups = [];

        foreach ($this->positions() as $position) {
            foreach ($position->groups as $group) {
                $groups[Str::slug($group->name)] = $group;
            }
        }

        uasort($groups, fn (PositionGroup $a, PositionGroup $b): int => strnatcasecmp($a->name, $b->name));

        return $groups;
    }

    /**
     * Katalog cech filtrowalnych z wypowiedzianą wartością na stanowiskach w sprzedaży:
     * flaga „tak" → `slug-cechy`, wybór → `slug-cechy:slug-opcji`. Liczby nie są przełącznikiem.
     *
     * @return array<string, string> klucz → etykieta
     */
    private function featureCatalogue(): array
    {
        $catalogue = [];

        foreach ($this->positions() as $position) {
            foreach ($position->attributeValues as $value) {
                [$key, $label] = $this->featureKey($value) ?? [null, null];

                if ($key !== null) {
                    $catalogue[$key] = $label;
                }
            }
        }

        asort($catalogue, SORT_NATURAL | SORT_FLAG_CASE);

        return $catalogue;
    }

    /** @return list<string> */
    private function featuresOf(Position $position): array
    {
        $keys = [];

        foreach ($position->attributeValues as $value) {
            $key = $this->featureKey($value)[0] ?? null;

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @return array{0: string, 1: string}|null */
    private function featureKey(PositionAttributeValue $value): ?array
    {
        $attribute = $value->attribute;

        if ($attribute === null || ! $attribute->is_filterable) {
            return null;
        }

        return match ($attribute->type) {
            PositionAttributeType::Flag => $value->value_flag ? [Str::slug($attribute->name), $attribute->name] : null,
            PositionAttributeType::Choice => $value->option !== null
                ? [Str::slug($attribute->name).':'.Str::slug($value->option->name), $attribute->name.': '.$value->option->name]
                : null,
            PositionAttributeType::Number => null,
        };
    }

    private function resolveWeek(?string $raw): CarbonImmutable
    {
        $day = $raw !== null ? $this->parseDate($raw) : null;

        return $day !== null ? CalendarWindow::Week->startFor($day) : $this->defaultWeek();
    }

    private function defaultWeek(): CarbonImmutable
    {
        $anchor = (new SaleCalendar($this->fishery))->anchor() ?? $this->today();

        return CalendarWindow::Week->startFor($anchor);
    }

    private function resolveAnglers(?string $raw): int
    {
        $max = max(1, (int) $this->positions()->max('max_anglers'));
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 1 && $value <= $max ? $value : 1;
    }

    private function resolveGroup(?string $raw): ?string
    {
        return $raw !== null && array_key_exists($raw, $this->groups()) ? $raw : null;
    }

    /** @return list<string> */
    private function resolveFeatures(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $known = $this->featureCatalogue();

        return array_values(array_unique(array_filter(
            explode(',', $raw),
            fn (string $key): bool => array_key_exists($key, $known),
        )));
    }

    private function parseDate(string $raw): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($raw, $this->fishery->timezoneName())->startOfDay();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->fishery->timezoneName())->startOfDay();
    }

    private function currency(): ?string
    {
        return $this->fishery->currency?->name;
    }
}
