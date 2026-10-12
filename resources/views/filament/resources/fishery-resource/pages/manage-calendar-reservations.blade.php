{{--
    Warstwa „Rezerwacje" kalendarza (zadania 042, 047) — warstwa operacyjna wg §3/§3a makiety etapu 3.

    Własny widok z klasami Tailwinda (wyjątek z panel-wlasciciela.md §1): siatka z colspanem pasków.
    Klasy w całości, nigdy sklejane. Widok NICZEGO NIE LICZY — siatkę składa ReservationCalendar
    (zajętość i blokady z PositionAvailability, oferta w pustych dobach z SaleCalendar), cenę zaznaczenia
    podaje warstwa oferty, a zapis idzie przez akcje strony (ReservationBooking).

    Popover jest WARSTWĄ nad siatką (position: absolute w kontenerze kalendarza), zakotwiczoną przy
    klikniętym elemencie [data-popover-anchor]: NAD jego wierszem, gdy mieści się na ekranie, inaczej POD nim
    (z przewinięciem do widoku), wyśrodkowany w poziomie w granicach kontenera. Nigdy w wierszu zaznaczenia. Stan zaznaczenia żyje w Livewire; Alpine tylko pozycjonuje,
    zamyka (Esc, ✕, klik poza kalendarzem) i przesuwa fokus strzałkami (Enter = klik przycisku).
--}}
@php
    $calendar = $this->calendar();
    $missing = $calendar->missingSetup();
    $grid = $this->getReservationGrid();
    $counters = $this->reservationCalendar()->counters();
    $selection = $this->selection();
    $selected = $this->selectedReservation();
    $selectedBlock = $this->selectedBlock();
    $locale = app()->getLocale();
    $fishery = $this->fishery();
    $timezone = $fishery->timezoneName();
    $currency = $fishery->currency?->name;
    $filter = $this->statusFilter;
    $barClasses = [
        'confirmed' => 'border border-emerald-600 bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
        'paid' => 'bg-emerald-700 text-white shadow-sm',
        'pending' => 'border border-dashed border-amber-600 bg-[repeating-linear-gradient(-45deg,#fef3c7_0_5px,#fde68a_5px_10px)] text-amber-900',
        'in_progress' => 'bg-slate-700 text-white shadow-sm',
    ];
    $blockClasses = 'border border-gray-400/60 bg-[repeating-linear-gradient(-45deg,rgba(156,163,175,.15)_0_5px,rgba(156,163,175,.4)_5px_10px)] text-gray-700 dark:text-gray-200';
    $popoverKey = $selection !== null
        ? 'sel-'.$selection['position']->id.'-'.$selection['from'].'-'.$selection['to']
        : ($selected !== null ? 'res-'.$selected->id : ($selectedBlock !== null ? 'blk-'.$selectedBlock->id : null));
    $chips = [
        'arrivals' => [__('Arrivals today'), $counters['arrivals'], 'gray'],
        'departures' => [__('Departures today'), $counters['departures'], 'gray'],
        'expiring' => [__('Unconfirmed (≤ 24 h)'), $counters['expiring'], 'warning'],
        'pending' => [__('Unconfirmed'), $counters['unconfirmed'], 'warning'],
        'to_contact' => [__('To contact'), $counters['to_contact'], 'danger'],
    ];
    $nightsLabel = fn (int $count): string => trans_choice(':count night|:count nights', $count, ['count' => $count]);
    // Kształt warstwy nad dniami (model połówek) — wspólny z kalendarzem portalu: `CalendarBarShape`.
    $barShape = function (int $days, bool $slantStart, bool $slantEnd): array {
        $css = \App\Support\CalendarBarShape::css($days, $slantStart, $slantEnd);

        return ['style' => 'z-index:5;'.$css['style'], 'name' => $css['top'], 'meta' => $css['bottom']];
    };
@endphp

@if ($missing !== null)
    <x-filament::section>
        <x-slot name="heading">{{ __('There is nothing to show yet') }}</x-slot>
        <p>
            @if ($missing === 'fishing_day')
                {{ __('This fishery has no fishing day hours, so there is nothing to price. Set them on "Sale and seasons".') }}
            @elseif ($missing === 'sale_period')
                {{ __('This fishery has no sale period, which is a refusal rather than unlimited sale. Add one on "Sale and seasons".') }}
            @else
                {{ __('This fishery has no positions yet. Add them on "Positions".') }}
            @endif
        </p>
    </x-filament::section>
@else
    {{-- Liczniki-filtry, filtry i nawigacja (§3, A8). --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2" data-reservation-counters>
            @foreach ($chips as $key => [$label, $count, $tone])
                <button
                    type="button"
                    wire:click="toggleFilter('{{ $key }}')"
                    data-filter-chip="{{ $key }}"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                        'border-primary-600 bg-primary-600 text-white' => $filter === $key,
                        'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/15 dark:bg-white/5 dark:text-gray-200' => $filter !== $key && $tone === 'gray',
                        'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300' => $filter !== $key && $tone === 'warning',
                        'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300' => $filter !== $key && $tone === 'danger',
                    ])
                >
                    {{ $label }} <span class="font-normal opacity-80">{{ $count }}</span>
                </button>
            @endforeach
            <button
                type="button"
                wire:click="toggleFilter('attention')"
                data-filter-chip="attention"
                aria-pressed="{{ $filter === 'attention' ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                    'border-primary-600 bg-primary-600 text-white' => $filter === 'attention',
                    'border-dashed border-gray-400 text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' => $filter !== 'attention',
                ])
            >
                {{ __('Needs action') }}
            </button>
            <div class="ms-auto">
                @can('create', \App\Models\Reservation::class)
                    <x-filament::button size="sm" icon="heroicon-m-plus" tag="a" :href="$this->newReservationUrl()">{{ __('New reservation') }}</x-filament::button>
                @endcan
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 text-xs">
            <div class="inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-white/15" role="group" aria-label="{{ __('Window') }}">
                @foreach (\App\Enums\CalendarWindow::optionsFor(\App\Enums\CalendarLayer::Reservations) as $value => $label)
                    <button type="button" wire:click="$set('window', '{{ $value }}')" @class([
                        'rounded-md px-3 py-1 font-semibold',
                        'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $this->unit()->value === $value,
                        'text-gray-700 dark:text-gray-300' => $this->unit()->value !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($this->groupOptions() !== [])
                <select wire:model.live="groupId" aria-label="{{ __('Position group') }}" class="rounded-lg border border-gray-300 bg-transparent px-2 py-1 dark:border-white/15">
                    <option value="">{{ __('Group: all') }}</option>
                    @foreach ($this->groupOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="statusFilter" aria-label="{{ __('Status') }}" class="rounded-lg border border-gray-300 bg-transparent px-2 py-1 dark:border-white/15">
                <option value="">{{ __('Status: all') }}</option>
                <option value="pending">{{ __('Awaiting confirmation') }}</option>
                <option value="confirmed">{{ __('Confirmed') }}</option>
                <option value="paid">{{ __('Paid') }}</option>
            </select>
            <div class="ms-auto flex items-center gap-2">
                <x-filament::button size="sm" color="gray" wire:click="goToToday">{{ __('Today') }}</x-filament::button>
                <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" wire:click="previousWindow" :label="__('Previous')" />
                @if ($grid !== null)
                    <span class="min-w-40 text-center text-sm font-semibold">
                        {{ $grid->days[0]->locale($locale)->isoFormat('D MMM') }} – {{ $grid->days[count($grid->days) - 1]->locale($locale)->isoFormat('D MMM YYYY') }}
                    </span>
                @endif
                <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" wire:click="nextWindow" :label="__('Next')" />
            </div>
        </div>
    </div>

    @if ($grid !== null)
        {{-- Komputer: siatka stanowisk × dób z paskami; popover jako warstwa w tym kontenerze. --}}
        <div
            class="relative mt-3 hidden md:block"
            data-calendar-container
            x-data="{
                lastRow: null,
                lastSide: null,
                busy() { return $wire.selPosition !== null || $wire.selReservation !== null || $wire.selBlock !== null },
                outside(event) {
                    if (! this.busy() || event.target.closest('.fi-modal, .fi-no, .fi-dropdown-panel')) return;
                    $wire.clearSelection();
                },
                navigate(event) {
                    const moves = { ArrowLeft: [0, -1], ArrowRight: [0, 1], ArrowUp: [-1, 0], ArrowDown: [1, 0] };
                    const move = moves[event.key];
                    const current = event.target.closest('[data-nav-row]');
                    if (! move || ! current) return;
                    event.preventDefault();
                    const row = Number(current.dataset.navRow) + move[0];
                    const from = Number(current.dataset.navCol);
                    const column = move[1] > 0 ? from + Number(current.dataset.navSpan) : (move[1] < 0 ? from - 1 : from);
                    const next = [...this.$root.querySelectorAll('[data-nav-row=\'' + row + '\']')]
                        .find((el) => column >= Number(el.dataset.navCol) && column < Number(el.dataset.navCol) + Number(el.dataset.navSpan));
                    next?.focus();
                },
                place(popover) {
                    const root = popover.closest('[data-calendar-container]');
                    const anchor = root?.querySelector('[data-popover-anchor]');
                    if (! anchor) return 'display:none';
                    const box = root.getBoundingClientRect();
                    const at = anchor.getBoundingClientRect();
                    const width = popover.offsetWidth;
                    const height = popover.offsetHeight;
                    // Nad wierszem, gdy mieści się na ekranie, w przeciwnym razie pod nim — nigdy w wierszu
                    // zaznaczenia, żeby dało się kliknąć kolejną dobę (wydłużyć pobyt w obie strony).
                    // Strona wybrana dla wiersza zostaje przy wydłużaniu zaznaczenia w tym wierszu — okienko nie
                    // przeskakuje (przewinięcie po pokazaniu pod wierszem zmienia miejsce nad nim).
                    const row = anchor.dataset.navRow ?? null;
                    const above = row !== null && row === this.lastRow && this.lastSide !== null
                        ? this.lastSide === 'above'
                        : at.top - 8 >= height;
                    this.lastRow = row;
                    this.lastSide = above ? 'above' : 'below';
                    const top = above ? at.top - box.top - 8 - height : at.bottom - box.top + 8;
                    const left = Math.max(4, Math.min(at.left + at.width / 2 - box.left - width / 2, box.width - width - 4));
                    if (! above) requestAnimationFrame(() => popover.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
                    return 'left:' + left + 'px;top:' + top + 'px';
                },
            }"
            x-on:keydown.escape.window="busy() && $wire.clearSelection()"
            x-on:click.outside="outside($event)"
            x-on:keydown="navigate($event)"
        >
            <div class="max-h-[70vh] overflow-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <table class="w-full table-fixed border-separate border-spacing-0 text-xs" data-reservation-grid>
                    <thead>
                        <tr>
                            <th class="sticky top-0 left-0 z-30 w-40 border-r border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-semibold text-gray-500 dark:border-white/10 dark:bg-gray-800">{{ __('Position') }}</th>
                            @foreach ($grid->days as $day)
                                <th @class([
                                    'sticky top-0 z-20 border-r border-b border-gray-200 px-1 py-1.5 text-center font-semibold leading-tight dark:border-white/10',
                                    'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $grid->isWeekend($day),
                                    'bg-gray-50 text-gray-500 dark:bg-gray-800' => ! $grid->isWeekend($day),
                                    'shadow-[inset_0_-3px_0_var(--color-primary-600)]' => $grid->isToday($day),
                                    'opacity-50' => $day < $grid->today,
                                ])>
                                    @if ($grid->isToday($day))<span class="block text-[9px] uppercase tracking-wider text-primary-600">{{ __('today') }}</span>@endif
                                    {{ $day->locale($locale)->isoFormat('dd') }}
                                    <span class="block text-sm text-gray-900 dark:text-white">{{ $day->locale($locale)->isoFormat('DD.MM') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid->rows as $rowIndex => $row)
                            <tr wire:key="row-{{ $row->position->id }}">
                                <th class="sticky left-0 z-10 border-r border-b border-gray-200 bg-white px-3 py-1.5 text-left text-sm font-bold whitespace-nowrap dark:border-white/10 dark:bg-gray-900">
                                    {{ $row->position->name }}
                                    @if ($row->withdrawn)
                                        <span class="block text-[11px] font-normal text-gray-500">{{ __('withdrawn from sale') }}</span>
                                    @endif
                                </th>
                                @foreach ($row->cells as $index => $cell)
                                    @php
                                        $dayString = $cell->day->toDateString();
                                        $inSelection = $selection !== null && $selection['position']->id === $row->position->id
                                            && $dayString >= $selection['from'] && $dayString <= $selection['to'];
                                        $offer = ($cell->past || $cell->occupied) ? null : $cell->offer;
                                        $clickable = $cell->isClickable();
                                    @endphp
                                    <td @class([
                                        'relative border-r border-b border-gray-200 p-0 dark:border-white/10',
                                        'bg-amber-50/60 dark:bg-amber-500/5' => $grid->isWeekend($cell->day) && ! $cell->past,
                                        'bg-gray-50 dark:bg-white/5' => $cell->past,
                                    ])>
                                        <button
                                            type="button"
                                            @if ($clickable) wire:click="selectCell({{ $row->position->id }}, '{{ $dayString }}')" @else aria-disabled="true" @endif
                                            data-cell="{{ $row->position->id }}-{{ $dayString }}"
                                            data-nav-row="{{ $rowIndex }}" data-nav-col="{{ $index }}" data-nav-span="1"
                                            aria-label="{{ $row->position->name }} {{ $dayString }}"
                                            aria-pressed="{{ $inSelection ? 'true' : 'false' }}"
                                            @class([
                                                'group relative flex h-12 w-full flex-col leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500',
                                                'items-center justify-center' => ! $cell->morningOccupied,
                                                'items-end justify-end pe-1.5 pb-1' => $cell->morningOccupied,
                                                'cursor-not-allowed' => ! $clickable,
                                                'hover:bg-primary-50 dark:hover:bg-primary-500/10' => $clickable && ! $inSelection,
                                            ])
                                        >
                                            @if ($offer !== null && ! $inSelection)
                                                <span class="text-[10.5px] text-gray-400 group-hover:opacity-0 dark:text-gray-500" data-cell-offer>
                                                    @if ($offer->sellable && $offer->totalInCents !== null)
                                                        {{ \App\Services\AmountFormatter::forVisitor($offer->totalInCents, $currency) }}
                                                        <span class="block text-[9.5px]">{{ $nightsLabel((int) $offer->nights) }}</span>
                                                    @elseif ($offer->startEarlierOn !== null)
                                                        {{ __('start :day', ['day' => $offer->startEarlierOn->format('d.m')]) }}
                                                    @endif
                                                </span>
                                            @endif
                                            @if ($cell->isSelectable() && ! $inSelection)
                                                <span class="pointer-events-none absolute inset-0 grid place-items-center text-lg font-semibold text-primary-600 opacity-0 group-hover:opacity-100" aria-hidden="true">+</span>
                                            @endif
                                        </button>

                                        {{-- Paski rezerwacji: od połowy dnia przyjazdu do połowy dnia wyjazdu, ukośne końce „/". --}}
                                        @foreach ($row->barsStartingAt($index) as $bar)
                                            @php
                                                $reservation = $bar->reservation;
                                                $isSelected = $selected?->id === $reservation->id;
                                                $total = $bar->totalInCents();
                                                $shape = $barShape($bar->span, ! $bar->startsBefore, ! $bar->endsAfter);
                                            @endphp
                                            <button
                                                type="button"
                                                wire:click="selectReservation({{ $reservation->id }})"
                                                wire:key="bar-{{ $reservation->id }}"
                                                data-reservation-bar="{{ $reservation->id }}"
                                                @if ($isSelected) data-popover-anchor @endif
                                                title="{{ $bar->label() }} · {{ $reservation->stayLabel() }}"
                                                style="{{ $shape['style'] }}"
                                                @class([
                                                    'absolute top-1 bottom-1 left-0 flex flex-col justify-center overflow-hidden text-left leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                                    $barClasses[$bar->kind],
                                                    'opacity-35' => $bar->dimmed,
                                                    'brightness-90' => $isSelected,
                                                ])
                                            >
                                                <span class="flex min-w-0 items-center gap-1 overflow-hidden font-semibold" style="{{ $shape['name'] }}">
                                                    @if ($bar->startsBefore)<span class="shrink-0" aria-label="{{ __('started earlier') }}">◀</span>@endif
                                                    @if ($reservation->needsContact())<span class="shrink-0 rounded bg-white px-1 text-[10px] font-bold text-red-600" aria-label="{{ __('To contact') }}">☎</span>@endif
                                                    @if ($bar->kind === 'pending')<x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5 shrink-0" />@endif
                                                    {{-- Wyjątek od dzisiejszej konfiguracji (044: „zostaw" przy blokadzie/wycofaniu, reguły sprzedaży). --}}
                                                    @if ($exceptionLabels = $bar->exceptionLabels())
                                                        <span class="shrink-0" data-bar-exception title="{{ implode(' · ', $exceptionLabels) }}" aria-label="{{ implode(' · ', $exceptionLabels) }}"><x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" /></span>
                                                    @endif
                                                    <span class="min-w-0 truncate">{{ $bar->label() }}</span>
                                                </span>
                                                <span class="truncate text-[10.5px] opacity-90" style="{{ $shape['meta'] }}">
                                                    {{ trans_choice(':count person|:count people', $bar->people(), ['count' => $bar->people()]) }}
                                                    · {{ \App\Filament\Resources\ReservationResource::statusLabel($reservation) }}
                                                    @if ($total !== null) · <span data-bar-total>{{ \App\Services\AmountFormatter::forVisitor($total, $currency) }}</span>@endif
                                                    @if ($bar->kind === 'pending' && $reservation->confirm_by)
                                                        · {{ __('expires :date', ['date' => $reservation->local($reservation->confirm_by)?->format('d.m H:i')]) }}
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach

                                        {{-- Blokada: doby od „od" do „do" — ten sam kształt co rezerwacja (przełom dnia). --}}
                                        @foreach ($row->blocksStartingAt($index) as $blockBar)
                                            @php
                                                $isSelected = $selectedBlock?->id === $blockBar->block->id;
                                                $shape = $barShape($blockBar->span, ! $blockBar->startsBefore, ! $blockBar->endsAfter);
                                            @endphp
                                            <button
                                                type="button"
                                                wire:click="selectBlock({{ $blockBar->block->id }})"
                                                wire:key="block-{{ $blockBar->block->id }}-{{ $row->position->id }}-{{ $index }}"
                                                data-block-bar="{{ $blockBar->block->id }}"
                                                @if ($isSelected) data-popover-anchor @endif
                                                title="{{ $blockBar->label() }}"
                                                style="{{ $shape['style'] }}"
                                                @class([
                                                    'absolute top-1 bottom-1 left-0 flex flex-col justify-center overflow-hidden text-left leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                                    $blockClasses,
                                                    'brightness-90' => $isSelected,
                                                ])
                                            >
                                                @if ($blockBar->nights() > 0)
                                                    <span class="truncate font-semibold" style="{{ $shape['name'] }}">{{ $blockBar->label() }}</span>
                                                    <span class="truncate text-[10.5px] opacity-80" style="{{ $shape['meta'] }}">{{ __('block') }} · {{ $nightsLabel($blockBar->nights()) }}</span>
                                                @endif
                                            </button>
                                        @endforeach

                                        {{-- Zaznaczenie: od połowy dnia przyjazdu do połowy dnia wyjazdu (kotwica popovera). --}}
                                        @if ($selection !== null && $selection['position']->id === $row->position->id && $dayString === $selection['from'])
                                            @php
                                                $lastIndex = count($row->cells) - 1;
                                                $departureIndex = $index + $selection['nights'];
                                                $selShape = $barShape(min($lastIndex, $departureIndex) - $index + 1, true, $departureIndex <= $lastIndex);
                                            @endphp
                                            <span
                                                data-popover-anchor
                                                data-selection-overlay
                                                style="{{ $selShape['style'] }}"
                                                class="pointer-events-none absolute top-1 bottom-1 left-0 bg-primary-500/30"
                                                aria-hidden="true"
                                            ></span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($popoverKey !== null)
                <div
                    wire:key="popover-{{ $popoverKey }}"
                    x-data="{ style: 'visibility:hidden' }"
                    x-init="$nextTick(() => { style = place($el) })"
                    x-on:resize.window="style = place($el)"
                    x-bind:style="style"
                    class="absolute z-40 w-80 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-xl dark:border-white/10 dark:bg-gray-900"
                    role="dialog"
                    @if ($selection !== null) data-selection-popover @elseif ($selected !== null) data-reservation-popover @else data-block-popover @endif
                >
                    <button type="button" wire:click="clearSelection" class="absolute top-2.5 right-3 text-gray-400 hover:text-gray-700" aria-label="{{ __('Close') }}">✕</button>

                    @if ($selection !== null)
                        <h5 class="me-6 font-semibold">{{ $selection['position']->name }} · {{ \Carbon\CarbonImmutable::parse($selection['from'])->locale($locale)->isoFormat('dd DD.MM') }} → {{ $selection['departure']?->setTimezone($timezone)->locale($locale)->isoFormat('dd DD.MM') }}</h5>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">
                            {{ $nightsLabel($selection['nights']) }}
                            · {{ __('arrival from :time, departure until :time2', ['time' => $selection['arrival']?->setTimezone($timezone)->format('H:i'), 'time2' => $selection['departure']?->setTimezone($timezone)->format('H:i')]) }}
                        </p>
                        @if ($selection['price'] !== null)
                            <p class="mt-1">{{ __('from the offer about') }} <strong data-selection-price>{{ $selection['price'] }}</strong></p>
                        @endif
                        @if ($selection['refusal'] !== null)
                            <p class="mt-2 rounded-md border border-amber-400/60 bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"><strong>{{ __('Outside the sale rules') }}:</strong> {{ $selection['refusal'] }}</p>
                        @endif
                        @if ($selection['nights'] === 1)
                            <p class="mt-2 rounded-md bg-gray-50 px-2 py-1.5 text-xs text-gray-500 dark:bg-white/5">{{ __('Click the departure day in this row to extend the stay (max. :nights).', ['nights' => $nightsLabel(max(1, $selection['max_nights']))]) }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            {{ ($this->quickBookAction)(['position' => $selection['position']->id, 'date' => $selection['from'], 'nights' => $selection['nights']]) }}
                            {{ ($this->quickBlockAction)(['position' => $selection['position']->id, 'from' => $selection['from'], 'to' => $selection['to']]) }}
                            <x-filament::button size="sm" color="gray" tag="a" :href="$this->fullFormUrl($selection['position']->id, $selection['from'], $selection['nights'])">{{ __('Full form →') }}</x-filament::button>
                        </div>
                    @elseif ($selected !== null)
                        @php
                            $contact = $selected->angler?->effectiveContact() ?? [];
                            $selectedTotal = $selected->terms?->total_cents;
                        @endphp
                        <h5 class="me-6 font-semibold">{{ $contact['name'] ?? $selected->terms?->contact_snapshot['name'] ?? '' }} <span class="ms-1 rounded-full border border-gray-200 px-2 text-[10.5px] font-semibold text-gray-500">#{{ $selected->id }}</span></h5>
                        <x-filament::badge class="mt-1 inline-flex" :color="\App\Filament\Resources\ReservationResource::statusColor($selected->status)">{{ \App\Filament\Resources\ReservationResource::statusLabel($selected) }}</x-filament::badge>
                        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                            <dt class="text-gray-500">{{ __('Position') }}</dt><dd>{{ $selected->position?->name }}</dd>
                            <dt class="text-gray-500">{{ __('Term') }}</dt><dd>{{ $selected->stayLabel() }}</dd>
                            <dt class="text-gray-500">{{ __('People') }}</dt><dd>{{ (int) ($selected->terms->anglers ?? 1) + (int) ($selected->terms->companions ?? 0) }}</dd>
                            @if ($selected->angler !== null)
                                <dt class="text-gray-500">{{ __('Angler data') }}</dt><dd>{{ $selected->angler->status->accountLabel() }}</dd>
                            @endif
                            <dt class="text-gray-500">{{ __('Amount') }}</dt><dd>{{ $selectedTotal !== null ? \App\Services\AmountFormatter::cents($selectedTotal, $currency) : '—' }} · {{ $selected->payment_status->label() }}</dd>
                            @if ($selected->confirm_by !== null && $selected->status === \App\Enums\ReservationStatus::Pending)
                                <dt class="text-gray-500">{{ __('Expires') }}</dt><dd>{{ $selected->local($selected->confirm_by)?->format('d.m H:i') }}</dd>
                            @endif
                            @if (filled($contact['phone'] ?? null))
                                <dt class="text-gray-500">{{ __('Phone') }}</dt><dd>{{ $contact['phone'] }}</dd>
                            @endif
                            @if (blank($contact['email'] ?? null))
                                <dt class="text-gray-500">{{ __('Contact') }}</dt><dd>{{ __('phone only') }}</dd>
                            @endif
                        </dl>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Resources\ReservationResource::getUrl('view', ['record' => $selected])">{{ __('View') }}</x-filament::button>
                            {{ ($this->quickEditReservationAction)(['reservation' => $selected->id]) }}
                            {{ ($this->fullEditReservationAction)(['reservation' => $selected->id]) }}
                            {{ ($this->confirmReservationAction)(['reservation' => $selected->id]) }}
                            {{ ($this->markPaidAction)(['reservation' => $selected->id]) }}
                            {{ ($this->markContactedAction)(['reservation' => $selected->id]) }}
                            {{ ($this->cancelReservationAction)(['reservation' => $selected->id]) }}
                        </div>
                    @elseif ($selectedBlock !== null)
                        <h5 class="me-6 font-semibold">{{ __('Block') }} · {{ $selectedBlock->positions->pluck('name')->join(', ') }}</h5>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ filled($selectedBlock->reason) ? $selectedBlock->reason : __('No reason given') }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $selectedBlock->starts_on->format('d.m.Y') }} → {{ $selectedBlock->ends_on?->format('d.m.Y') ?? __('until revoked') }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @can('update', $selectedBlock)
                                <x-filament::button size="sm" tag="a" :href="$this->blockEditUrl($selectedBlock)">{{ __('Edit block') }}</x-filament::button>
                            @endcan
                            {{ ($this->deleteBlockAction)(['block' => $selectedBlock->id]) }}
                        </div>
                    @endif
                </div>
            @endif

            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded border border-emerald-600 bg-emerald-100 dark:bg-emerald-500/20"></span>{{ __('Confirmed') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-emerald-700"></span>{{ __('Paid') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded border border-dashed border-amber-600 bg-amber-200"></span>{{ __('Awaiting confirmation (expires)') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-slate-700"></span>{{ __('In progress') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded {{ $blockClasses }}"></span>{{ __('Block') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-amber-100 dark:bg-amber-500/20"></span>{{ __('Weekend') }}</span>
                <span>{{ __('Grey figures — price of a stay starting that night') }}</span>
                <span>☎ {{ __('To contact') }}</span>
                <span>◀ {{ __('started earlier') }}</span>
            </div>
        </div>

        {{-- Telefon (§3a, A10): pasek dni + lista dnia; arkusz od dołu w dwóch krokach. --}}
        @php
            $phoneDay = collect($grid->days)->first(fn ($day) => $day->toDateString() === $this->phoneDay) ?? $grid->days[0];
            $phoneDayString = $phoneDay->toDateString();
            $arrivals = $departures = $onSite = $free = $other = [];

            foreach ($grid->rows as $row) {
                foreach ($row->bars as $bar) {
                    if ($bar->reservation->ends_at->setTimezone($timezone)->toDateString() === $phoneDayString) {
                        $departures[] = [$row, $bar];
                    }
                }

                $phoneBar = $row->barOn($phoneDay);
                $phoneCell = $row->cellOn($phoneDay);

                if ($phoneBar !== null) {
                    $phoneBar->reservation->first_night_on->toDateString() === $phoneDayString
                        ? $arrivals[] = [$row, $phoneBar]
                        : $onSite[] = [$row, $phoneBar];
                } elseif ($phoneCell !== null && $phoneCell->isSelectable() && $row->blockOn($phoneDay) === null && ($nights = $this->reservationCalendar()->freeNightsFrom($row->position, $phoneDay)) > 0) {
                    $free[] = [$row, $nights];
                } else {
                    $other[] = [$row, $row->blockOn($phoneDay)];
                }
            }
        @endphp
        <div
            class="relative mt-3 md:hidden"
            data-phone-view
            x-data="{
                sheet: null,
                async open(position, name, date, max) {
                    this.sheet = { position, name, date, max, nights: 1, quote: null };
                    await this.refresh();
                },
                async step(delta) {
                    if (! this.sheet) return;
                    this.sheet.nights = Math.max(1, Math.min(this.sheet.max, this.sheet.nights + delta));
                    await this.refresh();
                },
                async refresh() {
                    this.sheet.quote = await $wire.quote(this.sheet.position, this.sheet.date, this.sheet.nights);
                },
                next() {
                    const s = this.sheet;
                    this.sheet = null;
                    $wire.mountAction('quickBook', { position: s.position, date: s.date, nights: s.nights });
                },
            }"
            x-on:keydown.escape.window="sheet = null"
        >
            <div class="grid grid-cols-7 gap-1 pb-2">
                @foreach (array_slice($grid->days, 0, 7) as $day)
                    <button
                        type="button"
                        wire:click="selectPhoneDay('{{ $day->toDateString() }}')"
                        @class([
                            'rounded-lg border py-1 text-center text-xs font-bold',
                            'border-primary-600 bg-primary-600 text-white' => $day->isSameDay($phoneDay),
                            'border-amber-300 bg-amber-50 dark:bg-amber-500/10' => ! $day->isSameDay($phoneDay) && $grid->isWeekend($day),
                            'border-gray-300 dark:border-white/15' => ! $day->isSameDay($phoneDay) && ! $grid->isWeekend($day),
                        ])
                    >
                        <span class="block text-[9.5px] font-semibold uppercase opacity-80">{{ $day->locale($locale)->isoFormat('dd') }}</span>
                        {{ $day->format('d') }}
                    </button>
                @endforeach
            </div>

            @php $phonePast = $phoneDay < $grid->today; @endphp
            @foreach ([[__('Arrivals'), $arrivals], [__('Departures'), $departures], [__('On the fishery now'), $onSite]] as [$heading, $items])
                @if ($items !== [])
                    <div class="mt-2 rounded-md bg-gray-50 px-2 py-1 text-[11.5px] font-semibold text-gray-500 dark:bg-white/5">{{ $heading }}</div>
                    @foreach ($items as [$row, $bar])
                        <button type="button" wire:click="selectReservation({{ $bar->reservation->id }})" class="flex w-full items-center justify-between gap-3 border-t border-gray-200 px-1 py-2.5 text-left text-sm dark:border-white/10" wire:key="phone-{{ $heading }}-{{ $bar->reservation->id }}">
                            <span><strong>{{ $row->position->name }}</strong> · {{ $bar->label() }}</span>
                            <span class="text-xs text-gray-500">{{ \App\Filament\Resources\ReservationResource::statusLabel($bar->reservation) }}</span>
                        </button>
                    @endforeach
                @endif
            @endforeach

            <div class="mt-2 rounded-md bg-gray-50 px-2 py-1 text-[11.5px] font-semibold text-gray-500 dark:bg-white/5">{{ __('Free positions') }}</div>
            @forelse ($free as [$row, $nights])
                <button
                    type="button"
                    @if (! $phonePast) x-on:click="open({{ $row->position->id }}, @js($row->position->name), '{{ $phoneDayString }}', {{ $nights }})" @endif
                    class="flex w-full items-center justify-between gap-3 border-t border-gray-200 px-1 py-2.5 text-left text-sm dark:border-white/10"
                    wire:key="phone-free-{{ $row->position->id }}"
                    data-free-until
                >
                    <strong>{{ $row->position->name }}</strong>
                    <span class="text-xs text-gray-500">{{ __('free until :date', ['date' => $phoneDay->addDays($nights)->format('d.m')]) }} · {{ trans_choice('max. :count night|max. :count nights', $nights, ['count' => $nights]) }}</span>
                </button>
            @empty
                <p class="border-t border-gray-200 px-1 py-2.5 text-sm text-gray-500 dark:border-white/10">{{ __('No free positions on this day.') }}</p>
            @endforelse

            @foreach ($other as [$row, $blockBar])
                <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-1 py-2.5 text-sm text-gray-500 dark:border-white/10" wire:key="phone-other-{{ $row->position->id }}">
                    <strong>{{ $row->position->name }}</strong>
                    <span class="text-xs">{{ $blockBar !== null ? $blockBar->label() : ($phonePast ? __('past') : __('booked')) }}</span>
                </div>
            @endforeach

            {{-- Arkusz — krok 1: liczba dób, przyjazd i wyjazd, cena; „Dalej" otwiera krok 2 (dane wędkarza i zapis). --}}
            <div x-show="sheet" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-gray-950/50" x-on:click="sheet = null"></div>
            <div x-show="sheet" x-cloak x-transition class="fixed inset-x-0 bottom-0 z-50 rounded-t-2xl border-t border-gray-200 bg-white p-4 shadow-2xl dark:border-white/10 dark:bg-gray-900" role="dialog" data-phone-sheet>
                <template x-if="sheet">
                    <div>
                        <button type="button" class="absolute top-3 right-4 text-gray-400" x-on:click="sheet = null" aria-label="{{ __('Close') }}">✕</button>
                        <h5 class="me-6 text-base font-semibold" x-text="sheet.name"></h5>
                        <div class="my-3 flex items-center justify-center gap-6">
                            <button type="button" class="h-10 w-10 rounded-full border border-gray-300 text-xl" x-on:click="step(-1)" aria-label="{{ __('Fewer nights') }}">−</button>
                            <strong class="min-w-24 text-center text-xl" x-text="sheet.nights + ' / ' + sheet.max"></strong>
                            <button type="button" class="h-10 w-10 rounded-full border border-gray-300 text-xl" x-on:click="step(1)" aria-label="{{ __('More nights') }}">+</button>
                        </div>
                        <p class="text-center text-xs text-gray-500">{{ __('Nights') }}</p>
                        <template x-if="sheet.quote">
                            <div class="mt-3 text-sm">
                                <p><span class="text-gray-500">{{ __('Arrival') }}:</span> <span x-text="sheet.quote.arrival"></span> · <span class="text-gray-500">{{ __('Departure') }}:</span> <span x-text="sheet.quote.departure"></span></p>
                                <p x-show="sheet.quote.price"><span class="text-gray-500">{{ __('Price') }}:</span> <strong x-text="sheet.quote.price"></strong></p>
                                <p x-show="sheet.quote.refusal" class="mt-2 rounded-md border border-amber-400/60 bg-amber-50 p-2 text-xs text-amber-800"><strong>{{ __('Outside the sale rules') }}:</strong> <span x-text="sheet.quote.refusal"></span></p>
                            </div>
                        </template>
                        <div class="mt-4 flex justify-end">
                            <x-filament::button x-on:click="next()">{{ __('Next →') }}</x-filament::button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Zajęte stanowisko → arkusz z akcjami rezerwacji. --}}
            @if ($selected !== null)
                <div class="fixed inset-x-0 bottom-0 z-30 rounded-t-2xl border-t border-gray-200 bg-white p-4 text-sm shadow-2xl dark:border-white/10 dark:bg-gray-900" data-phone-reservation-sheet>
                    <button type="button" wire:click="clearSelection" class="absolute top-3 right-4 text-gray-400" aria-label="{{ __('Close') }}">✕</button>
                    <h5 class="me-6 font-semibold">{{ $selected->angler?->effectiveContact()['name'] }} · #{{ $selected->id }}</h5>
                    <p class="mt-1 text-xs text-gray-500">{{ $selected->position?->name }} · {{ $selected->stayLabel() }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Resources\ReservationResource::getUrl('view', ['record' => $selected])">{{ __('View') }}</x-filament::button>
                        {{ ($this->quickEditReservationAction)(['reservation' => $selected->id]) }}
                        {{ ($this->confirmReservationAction)(['reservation' => $selected->id]) }}
                        {{ ($this->markPaidAction)(['reservation' => $selected->id]) }}
                        {{ ($this->cancelReservationAction)(['reservation' => $selected->id]) }}
                    </div>
                </div>
            @endif
        </div>
    @endif
@endif
