{{--
    Warstwa „Rezerwacje" kalendarza (zadanie 042) — warstwa operacyjna.

    Własny widok z klasami Tailwinda (wyjątek z panel-wlasciciela.md §1): siatka z colspanem pasków.
    Klasy w całości, nigdy sklejane. Widok NICZEGO NIE LICZY — siatkę składa ReservationCalendar
    (zajętość i blokady z PositionAvailability), cenę zaznaczenia podaje warstwa oferty, a zapis idzie
    przez akcje strony (ReservationBooking).

    Popover jest WIERSZEM tabeli pod klikniętym stanowiskiem — zakotwiczony w kontenerze kalendarza,
    bez position: fixed, więc mieści się w polu widzenia także dla pierwszego i ostatniego wiersza.
    Komórki i paski to przyciski (fokus z klawiatury, Enter = klik); Esc i przycisk zamknięcia czyszczą
    zaznaczenie.
--}}
@php
    $calendar = $this->calendar();
    $missing = $calendar->missingSetup();
    $grid = $this->getReservationGrid();
    $counters = $this->reservationCalendar()->counters();
    $selection = $this->selection();
    $selected = $this->selectedReservation();
    $locale = app()->getLocale();
    $fishery = $this->fishery();
    $barClasses = [
        'confirmed' => 'bg-emerald-600 text-white',
        'paid' => 'bg-emerald-800 text-white',
        'pending' => 'border border-dashed border-amber-600 bg-amber-400/30 text-amber-900 dark:text-amber-200',
        'in_progress' => 'bg-sky-600 text-white',
    ];
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
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-reservation-counters>
        <x-filament::section compact><div class="text-xs opacity-70">{{ __('Arrivals today') }}</div><div class="text-xl font-semibold">{{ $counters['arrivals'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs opacity-70">{{ __('Departures today') }}</div><div class="text-xl font-semibold">{{ $counters['departures'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs opacity-70">{{ __('Unconfirmed') }}</div><div class="text-xl font-semibold">{{ $counters['unconfirmed'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs opacity-70">{{ __('To contact') }}</div><div class="text-xl font-semibold">{{ $counters['to_contact'] }}</div></x-filament::section>
    </div>

    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1 text-xs">
                <span>{{ __('Window') }}</span>
                <select wire:model.live="window" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                    @foreach (\App\Enums\CalendarWindow::optionsFor(\App\Enums\CalendarLayer::Reservations) as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @if ($this->groupOptions() !== [])
                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Position group') }}</span>
                    <select wire:model.live="groupId" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($this->groupOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="flex flex-col gap-1 text-xs">
                <span>{{ __('Status') }}</span>
                <select wire:model.live="statusFilter" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                    <option value="">{{ __('All') }}</option>
                    <option value="pending">{{ __('Awaiting confirmation') }}</option>
                    <option value="confirmed">{{ __('Confirmed') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                </select>
            </label>
            <div class="ml-auto flex gap-2">
                <x-filament::button size="sm" color="gray" wire:click="previousWindow">{{ __('Previous') }}</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="nextWindow">{{ __('Next') }}</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    @if ($grid !== null)
        {{-- Komputer: siatka stanowisk × dób z paskami. --}}
        <div class="hidden md:block" x-data x-on:keydown.escape.window="$wire.clearSelection()">
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-xs" data-reservation-grid>
                        <thead>
                            <tr>
                                <th class="sticky left-0 bg-inherit px-2 py-1.5 text-left">{{ __('Position') }}</th>
                                @foreach ($grid->days as $day)
                                    <th class="px-1 py-1.5 text-center font-semibold whitespace-nowrap {{ $grid->isWeekend($day) ? 'bg-gray-500/10' : '' }} {{ $grid->isToday($day) ? 'text-primary-600 underline' : '' }} {{ $day < $grid->today ? 'opacity-40' : '' }}">
                                        {{ $day->locale($locale)->isoFormat('D.MM') }}
                                        <div class="font-normal opacity-70">{{ $day->locale($locale)->isoFormat('dd') }}</div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grid->rows as $row)
                                <tr class="border-t border-gray-500/20" wire:key="row-{{ $row->position->id }}">
                                    <th class="sticky left-0 bg-inherit px-2 py-1.5 text-left font-medium whitespace-nowrap">
                                        {{ $row->position->name }}
                                        @if ($row->withdrawn)
                                            <span class="ml-1 rounded-full bg-gray-500/15 px-1.5 text-[11px] font-normal">{{ __('withdrawn') }}</span>
                                        @endif
                                    </th>
                                    @foreach ($row->cells as $index => $cell)
                                        @if ($row->isCoveredByBar($index))
                                            @continue
                                        @endif
                                        @if ($bar = $row->barStartingAt($index))
                                            <td colspan="{{ $bar->span }}" class="p-0.5">
                                                <button
                                                    type="button"
                                                    wire:click="selectReservation({{ $bar->reservation->id }})"
                                                    data-reservation-bar="{{ $bar->reservation->id }}"
                                                    class="flex w-full items-center gap-1 truncate rounded-md px-1.5 py-1 text-left font-medium {{ $barClasses[$bar->kind] }} {{ $selected?->id === $bar->reservation->id ? 'ring-2 ring-primary-500' : '' }}"
                                                    title="{{ $bar->label() }} · {{ $bar->reservation->stayLabel() }}"
                                                >
                                                    @if ($bar->startsBefore)<span aria-label="{{ __('started earlier') }}">◀</span>@endif
                                                    @if ($bar->reservation->needsContact())<span aria-label="{{ __('To contact') }}">☎</span>@endif
                                                    <span class="truncate">{{ $bar->label() }}</span>
                                                    @if ($bar->kind === 'pending' && $bar->reservation->confirm_by)
                                                        <span class="opacity-80">· {{ __('until :date', ['date' => $bar->reservation->local($bar->reservation->confirm_by)?->format('d.m H:i')]) }}</span>
                                                    @endif
                                                </button>
                                            </td>
                                        @else
                                            @php
                                                $inSelection = $this->selPosition === $row->position->id && $this->selStart !== null
                                                    && $cell->day->toDateString() >= $this->selStart && $cell->day->toDateString() <= $this->selEnd;
                                            @endphp
                                            <td class="p-0.5 {{ $grid->isWeekend($cell->day) ? 'bg-gray-500/5' : '' }}">
                                                <button
                                                    type="button"
                                                    @if ($cell->isSelectable()) wire:click="selectCell({{ $row->position->id }}, '{{ $cell->day->toDateString() }}')" @else disabled @endif
                                                    data-cell="{{ $row->position->id }}-{{ $cell->day->toDateString() }}"
                                                    aria-label="{{ $row->position->name }} {{ $cell->day->toDateString() }}"
                                                    class="block h-7 w-full rounded-md {{ $cell->past ? 'cursor-not-allowed bg-gray-500/10 opacity-40' : '' }} {{ $cell->occupied ? 'bg-gray-500/30' : '' }} {{ $cell->blocked ? 'bg-[repeating-linear-gradient(45deg,rgba(107,114,128,.35)_0_4px,transparent_4px_8px)]' : '' }} {{ $inSelection ? 'bg-primary-500/40 ring-2 ring-primary-500' : (! $cell->past && ! $cell->occupied ? 'hover:bg-primary-500/15' : '') }}"
                                                ></button>
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>

                                @if ($selection !== null && $selection['position']->id === $row->position->id)
                                    <tr wire:key="popover-{{ $row->position->id }}" data-selection-popover>
                                        <td colspan="{{ count($grid->days) + 1 }}" class="p-1">
                                            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-primary-500/40 bg-white p-3 shadow-sm dark:bg-gray-900">
                                                <div>
                                                    <div class="font-semibold">{{ $row->position->name }}</div>
                                                    <div>{{ $selection['arrival']?->setTimezone($fishery->timezoneName())->format('d.m H:i') }} → {{ $selection['departure']?->setTimezone($fishery->timezoneName())->format('d.m H:i') }} · {{ trans_choice(':count night|:count nights', $selection['nights'], ['count' => $selection['nights']]) }}</div>
                                                    @if ($selection['price'])
                                                        <div class="font-semibold">{{ $selection['price'] }}</div>
                                                    @else
                                                        <div class="text-amber-700">{{ __('Outside the sale rules') }}: {{ $selection['refusal'] }}</div>
                                                    @endif
                                                    <div class="opacity-70">{{ __('Click another night in this row to extend the stay.') }}</div>
                                                </div>
                                                <div class="ml-auto flex flex-wrap gap-2">
                                                    {{ ($this->quickBookAction)(['position' => $row->position->id, 'date' => $selection['from'], 'nights' => $selection['nights']]) }}
                                                    {{ ($this->quickBlockAction)(['position' => $row->position->id, 'from' => $selection['from'], 'to' => $this->selEnd]) }}
                                                    <x-filament::button size="sm" color="gray" tag="a" :href="$this->fullFormUrl($row->position->id, $selection['from'], $selection['nights'])">{{ __('Full form →') }}</x-filament::button>
                                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" wire:click="clearSelection" :label="__('Close')" />
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                @if ($selected !== null && $selected->position_id === $row->position->id)
                                    <tr wire:key="reservation-popover-{{ $selected->id }}" data-reservation-popover>
                                        <td colspan="{{ count($grid->days) + 1 }}" class="p-1">
                                            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-primary-500/40 bg-white p-3 shadow-sm dark:bg-gray-900">
                                                <div>
                                                    <div class="font-semibold">{{ $selected->angler?->effectiveContact()['name'] }} · {{ \App\Filament\Resources\ReservationResource::statusLabel($selected) }}</div>
                                                    <div>{{ $selected->stayLabel() }} · {{ $selected->payment_status->label() }}</div>
                                                    <div class="opacity-70">{{ $selected->angler?->effectiveContact()['phone'] }}</div>
                                                </div>
                                                <div class="ml-auto flex flex-wrap gap-2">
                                                    <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Resources\ReservationResource::getUrl('view', ['record' => $selected])">{{ __('View') }}</x-filament::button>
                                                    {{ ($this->editReservationAction)(['reservation' => $selected->id]) }}
                                                    {{ ($this->confirmReservationAction)(['reservation' => $selected->id]) }}
                                                    {{ ($this->markPaidAction)(['reservation' => $selected->id]) }}
                                                    {{ ($this->markContactedAction)(['reservation' => $selected->id]) }}
                                                    {{ ($this->cancelReservationAction)(['reservation' => $selected->id]) }}
                                                    <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" wire:click="clearSelection" :label="__('Close')" />
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-emerald-600"></span>{{ __('Confirmed') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-emerald-800"></span>{{ __('Paid') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded border border-dashed border-amber-600 bg-amber-400/30"></span>{{ __('Awaiting confirmation') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-sky-600"></span>{{ __('In progress') }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-3 w-5 rounded bg-[repeating-linear-gradient(45deg,rgba(107,114,128,.35)_0_4px,transparent_4px_8px)]"></span>{{ __('Blocked') }}</span>
                    <span>☎ {{ __('To contact') }}</span>
                    <span>◀ {{ __('started earlier') }}</span>
                </div>
            </x-filament::section>
        </div>

        {{-- Telefon: pasek dni + lista stanowisk dla wybranego dnia. --}}
        @php
            $phoneDay = collect($grid->days)->first(fn ($day) => $day->toDateString() === $this->phoneDay) ?? $grid->days[0];
        @endphp
        <div class="md:hidden" data-phone-view>
            <div class="flex gap-1 overflow-x-auto pb-2">
                @foreach ($grid->days as $day)
                    <button
                        type="button"
                        wire:click="selectPhoneDay('{{ $day->toDateString() }}')"
                        class="min-w-12 rounded-lg px-2 py-1 text-center text-xs {{ $day->isSameDay($phoneDay) ? 'bg-primary-600 text-white' : 'bg-gray-500/10' }} {{ $grid->isWeekend($day) ? 'font-semibold' : '' }}"
                    >
                        {{ $day->locale($locale)->isoFormat('dd') }}<br>{{ $day->locale($locale)->isoFormat('D.MM') }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($grid->rows as $row)
                    @php
                        $phoneBar = $row->barOn($phoneDay);
                        $phoneCell = $row->cellOn($phoneDay);
                        $freeNights = ($phoneBar === null && $phoneCell?->isSelectable()) ? $this->reservationCalendar()->freeNightsFrom($row->position, $phoneDay) : 0;
                    @endphp
                    <div class="flex items-center gap-3 rounded-lg border border-gray-500/20 p-3" wire:key="phone-{{ $row->position->id }}">
                        <div class="font-medium">{{ $row->position->name }}</div>
                        <div class="text-xs">
                            @if ($phoneBar !== null)
                                <button type="button" wire:click="selectReservation({{ $phoneBar->reservation->id }})" class="rounded px-1.5 py-0.5 {{ $barClasses[$phoneBar->kind] }}">{{ $phoneBar->label() }}</button>
                            @elseif ($phoneCell?->past)
                                <span class="opacity-60">{{ __('past') }}</span>
                            @elseif ($freeNights > 0)
                                <span data-free-until>{{ __('free until :date', ['date' => $phoneDay->addDays($freeNights)->format('d.m')]) }} · {{ trans_choice('max. :count night|max. :count nights', $freeNights, ['count' => $freeNights]) }}</span>
                            @else
                                <span class="opacity-60">{{ $phoneCell?->blocked ? __('blocked') : __('booked') }}</span>
                            @endif
                        </div>
                        <div class="ml-auto">
                            @if ($phoneBar === null && $freeNights > 0)
                                {{ ($this->quickBookAction)(['position' => $row->position->id, 'date' => $phoneDay->toDateString(), 'nights' => 1]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($selected !== null)
                <div class="mt-3 rounded-lg border border-primary-500/40 p-3 text-xs">
                    <div class="font-semibold">{{ $selected->angler?->effectiveContact()['name'] }} · {{ $selected->stayLabel() }}</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        {{ ($this->editReservationAction)(['reservation' => $selected->id]) }}
                        {{ ($this->confirmReservationAction)(['reservation' => $selected->id]) }}
                        {{ ($this->markPaidAction)(['reservation' => $selected->id]) }}
                        {{ ($this->cancelReservationAction)(['reservation' => $selected->id]) }}
                    </div>
                </div>
            @endif
        </div>
    @endif
@endif
