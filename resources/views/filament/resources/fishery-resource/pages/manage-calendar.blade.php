{{--
    Kalendarz podglądowy konfiguracji — zadanie 019.

    Układ siatki to klasy Tailwinda z motywu paneli (`resources/css/filament/theme.css`,
    ADR-016): widoku nie da się złożyć ze standardowych komponentów, bo tabele Filamenta
    nie znają `colspan`. Klasa użyta w tym pliku trafia do arkusza dzięki `@source` motywu.
    ⚠️ Klasy piszemy w całości (`'bg-amber-700/15 text-amber-700'`), nigdy sklejane
    z fragmentów — skaner Tailwinda nie wykona kodu Blade.

    ⚠️ Widok NICZEGO NIE LICZY. Wszystkie odpowiedzi pochodzą z `SaleCalendar`, a ten pyta
    wyłącznie warstwy oferty. Pierwsza reguła sprzedażowa zapisana tutaj zrobiłaby z widoku
    drugie źródło prawdy.
--}}
<x-filament-panels::page>
    @php
        $calendar = $this->calendar();
        $missing = $calendar->missingSetup();
        $grid = $this->getGrid();
        $seasons = $calendar->seasons();
        $locale = app()->getLocale();
    @endphp

    @if ($missing !== null)
        {{-- ⚠️ Trzy stany puste ZASTĘPUJĄ siatkę: trzydzieści kolumn odmów nie jest
             odpowiedzią dla kogoś, kto dopiero konfiguruje obiekt. --}}
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
        @if ($calendar->hasNoRates())
            {{-- ⚠️ Czwarty stan pusty POPRZEDZA siatkę, a nie zastępuje jej: dziura w cenniku
                 bywa częściowa i wtedy widać, których dób dotyczy. --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('This fishery has no rates yet') }}</x-slot>
                <p>{{ __('Every night below will refuse with "no price". Add a rate on "Pricing".') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Season') }}</span>
                    <select wire:model.live="seasonId" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                        @foreach ($seasons as $season)
                            <option value="{{ $season->id }}">
                                {{ $season->name ?: $season->starts_on->translatedFormat('d.m.Y') }}
                                ({{ $season->starts_on->translatedFormat('d.m.Y') }}–{{ $season->ends_on->translatedFormat('d.m.Y') }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Window') }}</span>
                    <select wire:model.live="window" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                        @foreach (\App\Enums\CalendarWindow::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Anglers') }}</span>
                    <input type="number" min="1" wire:model.live="anglers" class="w-20 rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Companions') }}</span>
                    <input type="number" min="0" wire:model.live="companions" class="w-20 rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span>{{ __('Stay length') }}</span>
                    <input type="number" min="1" placeholder="{{ __('shortest possible') }}" wire:model.live="nights" class="w-35 rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5">
                </label>

                <div class="ml-auto flex gap-2">
                    <x-filament::button size="sm" color="gray" wire:click="previousWindow" :disabled="! $this->canGoBack()">
                        {{ __('Previous') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="nextWindow" :disabled="! $this->canGoForward()">
                        {{ __('Next') }}
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @if ($grid !== null)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $this->currentStart()->locale($locale)->isoFormat($this->unit() === \App\Enums\CalendarWindow::Month ? 'MMMM YYYY' : '[tydzień od] D.MM.YYYY') }}
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-xs">
                        <thead>
                            <tr>
                                <th class="sticky left-0 bg-inherit px-2 py-1.5 text-left">{{ __('Position') }}</th>
                                @foreach ($grid->days as $day)
                                    <th class="px-1 py-1.5 font-semibold whitespace-nowrap">
                                        {{ $day->locale($locale)->isoFormat('D') }}
                                        <div class="font-normal opacity-60">{{ $day->locale($locale)->isoFormat('dd') }}</div>
                                        @if ($grid->overlappingRates($day) > 1)
                                            {{-- ⚠️ Licznik przy KAŻDYM nachodzeniu, także przy identycznych kwotach:
                                                 dwie stawki na tę samą dobę są pomyłką zawsze.
                                                 ⚠️ Podpowiedź podaje ZWYCIĘZCĘ I KWOTY PRZEGRANYCH — sam licznik
                                                 nie zamyka pytania „czemu widzę 70, skoro wpisałem 90". --}}
                                            <div
                                                class="font-semibold text-amber-700"
                                                title="{{ $this->overlapTooltip($grid->ratesFor($day)) }}"
                                            >⚠ {{ $grid->overlappingRates($day) }}</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grid->rows as $row)
                                <tr class="border-t border-gray-500/20">
                                    <th class="sticky left-0 bg-inherit px-2 py-1.5 text-left font-medium whitespace-nowrap">
                                        {{ $row->position->name }}
                                        @if ($row->services !== [])
                                            {{-- ⚠️ Plakietka, nie rozwijany wiersz: siatka nie rośnie, a niedostępność
                                                 widać bez klikania — kolor i dopisek (zadanie 020). Pełna lista
                                                 w podpowiedzi. Komórki dób usług nie doliczają. --}}
                                            <span
                                                data-services-badge
                                                title="{{ $this->servicesTooltip($row) }}"
                                                class="ml-1.5 inline-block rounded-full px-1.5 py-px text-[11px] font-medium {{ $row->unavailableServices() > 0 ? 'bg-amber-700/15 text-amber-700' : 'bg-gray-500/15' }}"
                                            >{{ $this->servicesBadge($row) }}</span>
                                        @endif
                                    </th>

                                    @if ($row->withdrawn)
                                        {{-- ⚠️ JEDEN komunikat na całą szerokość zamiast trzydziestu identycznych
                                             komórek — przyczyna jest niezależna od dat. --}}
                                        <td colspan="{{ count($grid->days) }}" class="px-2 py-1.5 opacity-70">
                                            {{ __('Withdrawn from sale — no night of this position is for sale, regardless of dates.') }}
                                        </td>
                                    @else
                                        @foreach ($row->cells as $cell)
                                            <td class="p-1 text-center whitespace-nowrap">
                                                @if ($cell->sellable)
                                                    <span title="{{ __('A stay of :nights night(s) starting on this night.', ['nights' => $cell->nights]) }}">
                                                        {{ \App\Services\AmountFormatter::cents($cell->totalInCents) }}
                                                        <div class="opacity-60">{{ trans_choice(':count night|:count nights', $cell->nights, ['count' => $cell->nights]) }}</div>
                                                    </span>
                                                @elseif ($cell->startsEarlierElsewhere())
                                                    <span
                                                        class="opacity-75"
                                                        title="{{ __('This night sits inside a bundle that has to be bought whole. Start on :day.', ['day' => $cell->startEarlierOn->toDateString()]) }}"
                                                    >{{ __('start :day', ['day' => $cell->startEarlierOn->locale($locale)->isoFormat('D.MM')]) }}</span>
                                                @else
                                                    <span class="opacity-60" title="{{ $this->tooltipFor($cell) }}">
                                                        {{ $this->shortLabelFor($cell) }}
                                                    </span>
                                                @endif
                                            </td>
                                        @endforeach
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($grid->deadRates !== [])
                    {{-- ⚠️ Oznaczenie martwej stawki jest JEDNO NA REGULE, nie na każdej dobie. --}}
                    <div class="mt-3 rounded-lg bg-amber-700/10 px-3 py-2.5 text-amber-700">
                        <strong>{{ __('Some rates never win') }}</strong>
                        <ul class="mt-1.5 pl-4.5">
                            @foreach ($grid->deadRates as $rule)
                                <li>{{ __('The rate :rate never wins anywhere in its date range — a cheaper one covers all of it.', ['rate' => $this->deadRateDescription($rule)]) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
