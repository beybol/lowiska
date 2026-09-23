{{--
    Kalendarz podglądowy konfiguracji — zadanie 019.

    ⚠️ Widok używa STYLÓW WPISANYCH WPROST, a nie klas Tailwinda, i to jest świadome:
    panel nie rejestruje własnego motywu (ADR-016 czeka na migrację potoku zasobów
    z Tailwinda 3 na 4), więc klasa użyta tutaj nie miałaby skąd wziąć CSS-u i komponent
    wyglądałby poprawnie tylko przypadkiem. Po dołożeniu motywu to miejsce przepisuje się
    na klasy — treść i dane pozostają te same.

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
            <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end">
                <label style="display:flex; flex-direction:column; gap:4px; font-size:12px">
                    <span>{{ __('Season') }}</span>
                    <select wire:model.live="seasonId" style="padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                        @foreach ($seasons as $season)
                            <option value="{{ $season->id }}">
                                {{ $season->name ?: $season->starts_on->translatedFormat('d.m.Y') }}
                                ({{ $season->starts_on->translatedFormat('d.m.Y') }}–{{ $season->ends_on->translatedFormat('d.m.Y') }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label style="display:flex; flex-direction:column; gap:4px; font-size:12px">
                    <span>{{ __('Window') }}</span>
                    <select wire:model.live="window" style="padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                        @foreach (\App\Enums\CalendarWindow::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label style="display:flex; flex-direction:column; gap:4px; font-size:12px">
                    <span>{{ __('Anglers') }}</span>
                    <input type="number" min="1" wire:model.live="anglers" style="width:80px; padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                </label>

                <label style="display:flex; flex-direction:column; gap:4px; font-size:12px">
                    <span>{{ __('Companions') }}</span>
                    <input type="number" min="0" wire:model.live="companions" style="width:80px; padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                </label>

                <label style="display:flex; flex-direction:column; gap:4px; font-size:12px">
                    <span>{{ __('Stay length') }}</span>
                    <input type="number" min="1" placeholder="{{ __('shortest possible') }}" wire:model.live="nights" style="width:140px; padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                </label>

                <div style="display:flex; gap:8px; margin-left:auto">
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

                <div style="overflow-x:auto">
                    <table style="border-collapse:collapse; font-size:12px; width:100%">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:6px 8px; position:sticky; left:0; background:inherit">{{ __('Position') }}</th>
                                @foreach ($grid->days as $day)
                                    <th style="padding:6px 4px; font-weight:600; white-space:nowrap">
                                        {{ $day->locale($locale)->isoFormat('D') }}
                                        <div style="font-weight:400; opacity:.6">{{ $day->locale($locale)->isoFormat('dd') }}</div>
                                        @if ($grid->overlappingRates($day) > 1)
                                            {{-- ⚠️ Licznik przy KAŻDYM nachodzeniu, także przy identycznych kwotach:
                                                 dwie stawki na tę samą dobę są pomyłką zawsze. --}}
                                            <div
                                                style="font-weight:600; color:#B45309"
                                                title="{{ __(':count rates match this night; the cheapest one wins.', ['count' => $grid->overlappingRates($day)]) }}"
                                            >⚠ {{ $grid->overlappingRates($day) }}</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($grid->rows as $row)
                                <tr style="border-top:1px solid rgba(120,120,120,.2)">
                                    <th style="text-align:left; padding:6px 8px; font-weight:500; white-space:nowrap; position:sticky; left:0; background:inherit">
                                        {{ $row->position->name }}
                                    </th>

                                    @if ($row->withdrawn)
                                        {{-- ⚠️ JEDEN komunikat na całą szerokość zamiast trzydziestu identycznych
                                             komórek — przyczyna jest niezależna od dat. --}}
                                        <td colspan="{{ count($grid->days) }}" style="padding:6px 8px; opacity:.7">
                                            {{ __('Withdrawn from sale — no night of this position is for sale, regardless of dates.') }}
                                        </td>
                                    @else
                                        @foreach ($row->cells as $cell)
                                            <td style="padding:4px; text-align:center; white-space:nowrap">
                                                @if ($cell->sellable)
                                                    <span title="{{ __('A stay of :nights night(s) starting on this night.', ['nights' => $cell->nights]) }}">
                                                        {{ number_format($cell->totalInCents / 100, 2, ',', ' ') }}
                                                        <div style="opacity:.6">{{ trans_choice(':count night|:count nights', $cell->nights, ['count' => $cell->nights]) }}</div>
                                                    </span>
                                                @elseif ($cell->startsEarlierElsewhere())
                                                    <span
                                                        style="opacity:.75"
                                                        title="{{ __('This night sits inside a bundle that has to be bought whole. Start on :day.', ['day' => $cell->startEarlierOn->toDateString()]) }}"
                                                    >{{ __('start :day', ['day' => $cell->startEarlierOn->locale($locale)->isoFormat('D.MM')]) }}</span>
                                                @else
                                                    <span style="opacity:.6" title="{{ $this->tooltipFor($cell) }}">
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
                    <div style="margin-top:12px; padding:10px 12px; border-radius:8px; background:rgba(180,83,9,.1); color:#B45309">
                        <strong>{{ __('Some rates never win') }}</strong>
                        <ul style="margin:6px 0 0; padding-left:18px">
                            @foreach ($grid->deadRates as $rule)
                                <li>{{ __('The rate :amount never wins anywhere in its date range — a cheaper one covers all of it.', ['amount' => $rule->amount]) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
