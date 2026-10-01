{{--
    Kalendarz portalu — zadanie 033 (portal-v3 §3), wygląd wg makiety — zadanie 038. Dane: `PortalCalendar`
    (werdykty z `SaleCalendar` → warstwa oferty, ADR-015); widok NICZEGO nie liczy.

    ⚠️ Fragment jest renderowany i w pełnej stronie, i osobno — na żądanie `portal.js` z nagłówkiem
    `X-Portal-Fragment: calendar` (ADR-022). Linki przełączników to zwykłe adresy z parametrami:
    bez skryptu działają przeładowaniem, ze skryptem podmieniają wyłącznie ten fragment.
    Linki przełączników mają `rel="nofollow"` — robot nie mnoży wariantów strony.

    ⚠️ Weekend (sobota, niedziela) jest wyróżniony WYŁĄCZNIE w nagłówku dni (038, R3) — komórki z cenami
    zostają białe, bo kreskowanie `a100/a200` w komórce znaczy „niedostępne — powód od łowiska".
--}}
@php
    $rules = new \App\Services\FisheryRulesSummary($fishery);
    $days = $calendar->days();
    $rows = $calendar->rows();
    $packages = $calendar->packages();
    $groups = $calendar->groupOptions();
    $features = $calendar->featureOptions();
    $selectedIndex = $calendar->selectedDayIndex();
    $selectedPosition = $calendar->selectedPosition();
    $isWeekend = fn ($day): bool => $day->isoWeekday() >= 6;
    $chip = 'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-[12.5px] font-semibold transition';
    $chipOn = 'border-b800 bg-b800 text-white';
    $chipOff = 'border-line bg-white text-ink2 hover:border-b400';
    $navButton = 'inline-flex size-7 items-center justify-center rounded-sm border border-line bg-white text-[14px] font-semibold text-ink2 transition hover:border-b400';
    // Legenda pokazuje tylko to, co jest w widocznym tygodniu (038, U7).
    $cells = collect($rows)->flatMap(fn ($row) => $row['cells'] ?? []);
    $hasBundle = $packages !== [] || $cells->contains(fn ($cell) => $cell['state'] === 'bundle');
    $hasFromFishery = $cells->contains(fn ($cell) => ! in_array($cell['state'], ['sellable', 'bundle'], true) && ($cell['from_fishery'] ?? false));
@endphp
<section id="{{ \App\Services\PortalRoutes::CALENDAR_ANCHOR[app()->getLocale()] }}" data-calendar class="scroll-mt-4">
    {{-- Nagłówek: tytuł po lewej; „Łowiących" i nawigacja tygodnia po prawej — na telefonie zawijają się pod tytuł. --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="font-display text-[23px] font-semibold tracking-tight">{{ __('Dates and prices') }}</h2>
            <p class="mt-0.5 text-[13px] font-semibold text-a700">{{ __('The fishery will confirm free dates by phone.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="flex items-center gap-2">
                <span class="text-[12px] text-muted">{{ __('Anglers') }}</span>
                <span class="inline-flex rounded-sm border border-line bg-white p-0.5" role="group" aria-label="{{ __('Anglers') }}">
                    @foreach ($calendar->anglerOptions() as $option)
                        <a href="{{ $option['url'] }}" rel="nofollow" data-calendar-link
                           class="rounded-[5px] px-2.5 py-0.5 text-[12.5px] font-semibold {{ $option['active'] ? 'bg-b800 text-white' : 'text-ink2 hover:text-b700' }}"
                           @if ($option['active']) aria-current="true" @endif>{{ $option['count'] }}</a>
                    @endforeach
                </span>
            </div>
            <div class="flex items-center gap-2 text-[13px]">
                @if ($prev = $calendar->previousWeekUrl())
                    <a href="{{ $prev }}" rel="nofollow" data-calendar-link class="{{ $navButton }}" aria-label="{{ __('Previous week') }}">‹</a>
                @else
                    <span class="{{ $navButton }} cursor-default text-faint hover:border-line" aria-hidden="true">‹</span>
                @endif
                <b class="whitespace-nowrap">{{ $days[0]->locale(app()->getLocale())->isoFormat('D MMMM') }} – {{ $days[6]->locale(app()->getLocale())->isoFormat('D MMMM YYYY') }}</b>
                @if ($next = $calendar->nextWeekUrl())
                    <a href="{{ $next }}" rel="nofollow" data-calendar-link class="{{ $navButton }}" aria-label="{{ __('Next week') }}">›</a>
                @else
                    <span class="{{ $navButton }} cursor-default text-faint hover:border-line" aria-hidden="true">›</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Wyciąg zasad (§3.2): pełny na desktopie — pozycje z pogrubioną etykietą (038, U8); skrócony na telefonie. --}}
    @if (($entries = $rules->entries()) !== [])
        <p class="mt-3 hidden rounded-sm border border-line bg-surface px-3 py-2 text-[12.5px] text-ink2 lg:block">
            @foreach ($entries as $entry)
                @if (! $loop->first)<span class="mx-1 text-faint">·</span>@endif<span>@if ($entry['label'] !== null)<b class="font-semibold text-ink">{{ $entry['label'] }}</b>@endif{{ $entry['rest'] }}</span>
            @endforeach
        </p>
        <details class="mt-3 rounded-sm border border-line bg-surface px-3 py-2 text-[12px] text-ink2 lg:hidden">
            <summary class="flex cursor-pointer list-none justify-between gap-2 [&::-webkit-details-marker]:hidden">
                <span>{{ $rules->cardLine() }}</span>
                <span class="shrink-0 font-semibold text-b700">{{ __('Rules ›') }}</span>
            </summary>
            <p class="mt-2 border-t border-line pt-2">{{ $rules->line() }}</p>
        </details>
    @endif

    {{-- Przełączniki grup i cech — przewijane poziomo na telefonie. --}}
    @if ($groups !== [] || $features !== [])
        <div class="mt-3 -mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
            @foreach ($groups as $option)
                <a href="{{ $option['url'] }}" rel="nofollow" data-calendar-link class="{{ $chip }} {{ $option['active'] ? $chipOn : $chipOff }}"
                   @if ($option['active']) aria-current="true" @endif>{{ $option['label'] }}<span class="opacity-70">{{ $option['count'] }}</span></a>
            @endforeach
            @if ($groups !== [] && $features !== [])
                <span class="mx-1 w-px shrink-0 self-stretch bg-line" aria-hidden="true"></span>
            @endif
            @foreach ($features as $option)
                <a href="{{ $option['url'] }}" rel="nofollow" data-calendar-link class="{{ $chip }} {{ $option['active'] ? $chipOn : $chipOff }}"
                   @if ($option['active']) aria-pressed="true" @endif>{{ $option['label'] }}<span class="opacity-70">{{ $option['count'] }}</span></a>
            @endforeach
        </div>
    @endif

    @foreach ($calendar->notes() as $note)
        <p class="mt-2 rounded-sm border border-a200 bg-a100 px-3 py-1.5 text-[12.5px] font-semibold text-a700">{{ $note }}</p>
    @endforeach

    @if ($rows === [])
        <p class="mt-3 rounded-md border border-dashed border-faint bg-white px-4 py-5 text-[13.5px] text-muted">{{ __('No position matches the selected filters.') }}</p>
    @else
        {{-- ============ DESKTOP: siatka stanowiska × doby ============ --}}
        {{-- ⚠️ BEZ stałej wysokości i bez własnego przewijania — wysokość wynika z liczby stanowisk,
             a nagłówek dni przykleja się do okna przy przewijaniu strony (kontener z `overflow`
             przyciągnąłby `sticky` do siebie i przycinał podpowiedzi). --}}
        <div class="mt-3 hidden rounded-md border border-line bg-white shadow-1 lg:block" data-calendar-grid>
            <table class="w-full table-fixed border-separate border-spacing-0 text-[12px]">
                <colgroup><col class="w-44"><col span="7"></colgroup>
                <thead>
                    <tr>
                        <th class="sticky top-0 z-30 rounded-tl-md border-r border-b border-line bg-surface px-3 py-2 text-left text-[11px] font-semibold tracking-[.06em] text-muted uppercase">{{ __('Position') }}</th>
                        @foreach ($days as $day)
                            <th class="sticky top-0 z-20 border-b border-l border-line border-l-line2 px-2 py-1.5 text-center whitespace-nowrap {{ $isWeekend($day) ? 'bg-a100 text-a800' : 'bg-surface text-ink' }} {{ $loop->last ? 'rounded-tr-md' : '' }}">
                                <span class="block text-[10.5px] font-semibold tracking-[.06em] uppercase {{ $isWeekend($day) ? 'text-a800' : 'text-faint' }}">{{ $day->locale(app()->getLocale())->isoFormat('dd') }}</span>
                                <span class="block text-[13px] font-bold">{{ $day->format('d.m') }}</span>
                            </th>
                        @endforeach
                    </tr>
                    @if ($packages !== [])
                        <tr>
                            <th class="sticky top-[46px] z-30 border-r border-b border-line bg-surface px-3 py-1 text-left text-[11px] font-semibold text-b700">{{ __('Bundles') }}</th>
                            @php
                                $i = 0;
                            @endphp
                            @while ($i < 7)
                                @php
                                    $band = collect($packages)->first(fn ($b) => $b['from'] === $i);
                                @endphp
                                @if ($band)
                                    <th colspan="{{ $band['to'] - $band['from'] + 1 }}" class="sticky top-[46px] z-20 border-b border-l border-line border-l-line2 bg-surface px-1 py-1">
                                        <span class="block rounded-sm bg-b100 px-2 py-0.5 text-center text-[11px] font-semibold text-b800">{{ $band['label'] }}</span>
                                    </th>
                                    @php
                                        $i = $band['to'] + 1;
                                    @endphp
                                @else
                                    <th class="sticky top-[46px] z-20 border-b border-l border-line border-l-line2 bg-surface"></th>
                                    @php
                                        $i++;
                                    @endphp
                                @endif
                            @endwhile
                        </tr>
                    @endif
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row" class="border-r border-b border-line border-b-line2 bg-white px-3 py-2 text-left align-top font-normal">
                                @include('portal.partials.calendar-row-header', ['header' => $row['header']])
                            </th>
                            @if ($row['message'] !== null)
                                <td colspan="7" class="border-b border-line2 bg-a100/60 px-3 py-2 text-[12.5px] font-semibold text-a700">{{ $row['message'] }}</td>
                            @else
                                @foreach ($row['cells'] as $cell)
                                    <td class="border-b border-l border-line2 p-1 text-center align-middle">
                                        @include('portal.partials.calendar-cell', ['cell' => $cell, 'tooltip' => true])
                                    </td>
                                @endforeach
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ============ TELEFON: pasek dób, lista stanowisk dla doby, karta (§3.5) ============ --}}
        <div class="mt-3 lg:hidden">
            <div class="grid grid-cols-7 gap-1" role="tablist" aria-label="{{ __('Nights') }}">
                @foreach ($days as $index => $day)
                    @php
                        $inBundle = collect($packages)->contains(fn ($b) => $index >= $b['from'] && $index <= $b['to']);
                        $dayClass = match (true) {
                            $index === $selectedIndex => 'border-b700 bg-b700 text-white',
                            $isWeekend($day) => 'border-a200 bg-a100 text-a800',
                            default => 'border-line bg-white text-ink',
                        };
                    @endphp
                    <a href="{{ $calendar->dayUrl($day) }}" rel="nofollow" data-calendar-link role="tab" aria-selected="{{ $index === $selectedIndex ? 'true' : 'false' }}"
                       class="rounded-sm border px-1 py-1.5 text-center text-[13px] font-bold {{ $dayClass }} {{ $inBundle ? 'border-b-4 border-b-b400' : '' }}">
                        <span class="block text-[10.5px] font-semibold uppercase opacity-75">{{ $day->locale(app()->getLocale())->isoFormat('dd') }}</span>{{ $day->format('j') }}
                    </a>
                @endforeach
            </div>
            @foreach ($packages as $band)
                <p class="mt-1 text-[11.5px] font-semibold text-b700">{{ $band['label'] }}</p>
            @endforeach

            <ul class="mt-2 divide-y divide-line2 overflow-hidden rounded-md border border-line bg-white shadow-1">
                {{-- Nagłówek listy (`.mgh` makiety) — dla której doby są ceny. --}}
                <li class="bg-surface px-3 py-1.5 text-[11.5px] font-semibold text-muted">{{ __('Positions') }} · {{ $calendar->dayLabel($days[$selectedIndex]) }}</li>
                @foreach ($rows as $row)
                    @php
                        $cell = $row['cells'][$selectedIndex] ?? null;
                    @endphp
                    @php
                        $active = $selectedPosition === $row['header']['label'];
                    @endphp
                    <li>
                        <a href="{{ $calendar->positionUrl($row['position']) }}" rel="nofollow" data-calendar-link
                           class="flex items-start justify-between gap-3 px-3 py-2.5 {{ $active ? 'bg-b50' : '' }}">
                            <span class="min-w-0">@include('portal.partials.calendar-row-header', ['header' => $row['header']])</span>
                            <span class="shrink-0 text-right text-[12.5px]">
                                @if ($row['message'] !== null)
                                    <span class="font-semibold text-a700">{{ $row['message'] }}</span>
                                @elseif ($cell !== null)
                                    @include('portal.partials.calendar-cell', ['cell' => $cell, 'tooltip' => false])
                                @endif
                            </span>
                        </a>
                        @if ($active && $cell !== null && $cell['state'] === 'sellable')
                            <div class="mx-3 mb-3 rounded-md border border-b200 bg-b50 px-3 py-2.5">
                                @include('portal.partials.calendar-breakdown', ['tip' => $cell['tooltip']])
                            </div>
                        @elseif ($active && $cell !== null && $cell['state'] === 'bundle')
                            <p class="mx-3 mb-3 text-[12.5px] font-semibold text-b700">{{ $cell['title'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Legenda (U7) — wyłącznie pozycje, które występują w widocznym tygodniu. --}}
        <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-muted">
            <span><i class="mr-1 inline-block size-3 rounded-sm border border-line bg-white align-[-2px]"></i>{{ __('Price of a stay from this night') }}</span>
            @if ($hasBundle)
                <span><i class="mr-1 inline-block size-3 rounded-sm bg-b50 align-[-2px]"></i>{{ __('Inside a bundle — start earlier') }}</span>
            @endif
            @if ($hasFromFishery)
                <span><i class="mr-1 inline-block size-3 rounded-sm bg-[repeating-linear-gradient(-45deg,var(--color-a100),var(--color-a100)_3px,var(--color-a200)_3px,var(--color-a200)_6px)] align-[-2px]"></i>{{ __('Unavailable — reason from the fishery') }}</span>
            @endif
        </div>
    @endif
</section>
