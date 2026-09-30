{{--
    Strona łowiska — zadanie 032 (portal-v3 §1, makiety „Łowisko — Mapa i terminy" i „Szczegóły").

    ⚠️ **Obie zakładki w JEDNYM dokumencie** — treść „Szczegółów" jest w HTML-u i indeksowana
    (027 pkt 9.4). Przełącza je `resources/js/portal.js`; bez skryptu stoją jedna pod drugą.
    ⚠️ Widok NICZEGO nie liczy: dane składa `PortalFisheryPage`, „cenę od" — `PriceFrom`.
    ⚠️ Zdjęcia to ZAŚLEPKI z makiety (036). Telefon: nazwa → zakładki → mapa → [kalendarz] →
    cena i telefon → opis → zdjęcia, pasek „Zadzwoń" przyklejony do dołu.
--}}
@extends('portal.layout')

@use('App\Services\PortalRoutes')
@php
    $mapTab = PortalRoutes::fisheryTabAnchor('map');
    $detailsTab = PortalRoutes::fisheryTabAnchor('details');
    $mapUrl = $page->mapUrl();
    $description = $page->html($fishery->description);
    $photoCount = $page->photoCount();
    $phoneHref = $page->phoneHref();
@endphp

@section('title', $fishery->name)
@section('description', $page->metaDescription())

@section('content')
    <article class="pb-20 lg:pb-0">
        {{-- Zdjęcia NAD nazwą — tylko na szerszym ekranie; na telefonie trafiają na koniec. --}}
        <div class="hidden lg:block">
            @include('portal.partials.fishery-photos', ['photoCount' => $photoCount])
        </div>

        <header class="mx-auto max-w-6xl px-4 pt-5 sm:px-6">
            <h1 class="font-display text-[28px] font-semibold leading-tight tracking-tight sm:text-[30px]">{{ $fishery->name }}</h1>
            @if (($subtitle = $page->subtitle()) !== '')
                <p class="mt-1 text-[14px] text-muted">{{ $subtitle }}</p>
            @endif
        </header>

        <div data-tabs class="mx-auto max-w-6xl scroll-mt-4 px-4 sm:px-6">
            <nav class="mt-4 flex gap-6 border-b border-line text-[14px] font-semibold" role="tablist" aria-label="{{ $fishery->name }}">
                @foreach ([$mapTab => __('Map and dates'), $detailsTab => __('Details')] as $anchor => $label)
                    <a href="#{{ $anchor }}" data-tab-link="{{ $anchor }}" role="tab"
                       class="{{ $loop->first ? 'is-active' : '' }} -mb-px border-b-2 border-transparent pb-2.5 text-muted hover:text-b800 [&.is-active]:border-b700 [&.is-active]:text-b800">{{ $label }}</a>
                @endforeach
            </nav>

            {{-- ============ Mapa i terminy (domyślna) ============ --}}
            {{-- ⚠️ Kolejność w HTML = kolejność na telefonie (mapa → kalendarz → cena i telefon → opis);
                 na desktopie siatka CSS stawia box obok mapy, a kalendarz POD nimi, na całą szerokość —
                 w jednej kolumnie siedem dób się nie mieściło i włączało się przewijanie w poziomie. --}}
            <section id="{{ $mapTab }}" data-tab-panel="{{ $mapTab }}" role="tabpanel" class="grid gap-7 py-5 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="lg:col-start-1 lg:row-start-1">
                    @if ($mapUrl !== null)
                        <figure>
                            <img src="{{ $mapUrl }}" alt="{{ __('Fishery map — :fishery', ['fishery' => $fishery->name]) }}" class="w-full rounded-md border border-line bg-white" loading="lazy">
                            <figcaption class="mt-1.5 text-[11.5px] text-muted">{{ __('Map of the positions — position numbers as in the calendar.') }}</figcaption>
                        </figure>
                    @endif

                </div>

                {{-- Kalendarz — zadanie 033: płaska siatka stanowisk z warstwy oferty (ADR-015, ADR-022). --}}
                <div class="min-w-0 lg:col-span-2 lg:row-start-2">
                    @include('portal.partials.calendar')
                </div>

                <aside class="lg:col-start-2 lg:row-start-1">
                    @include('portal.partials.fishery-box')

                    @if ($description !== null)
                        <h2 class="mt-6 font-display text-[19px] font-semibold">{{ __('About the fishery') }}</h2>
                        <div class="prose-portal mt-2 text-[14px] leading-relaxed text-ink2">{{ $description }}</div>
                    @endif
                    {{-- Przycisk, nie kotwica: przełącza zakładkę bez skoku strony. Bez JS obie treści są
                         widoczne, więc przycisk pojawia się dopiero ze skryptem (`hidden` zdejmuje `portal.js`). --}}
                    <button type="button" data-tab-open="{{ $detailsTab }}" hidden class="mt-3 inline-block cursor-pointer text-left text-[13px] font-semibold text-b700 hover:underline">{{ __('Access, positions, amenities — Details →') }}</button>
                </aside>
            </section>

            {{-- ============ Szczegóły ============ --}}
            <section id="{{ $detailsTab }}" data-tab-panel="{{ $detailsTab }}" role="tabpanel" class="grid gap-7 py-5 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="space-y-7">
                    @if (($water = $page->waterParameters()) !== [])
                        <div>
                            <h2 class="font-display text-[19px] font-semibold">{{ __('The water') }}</h2>
                            @include('portal.partials.parameters', ['items' => $water])
                            @if (($records = $page->html($fishery->records)) !== null)
                                <div class="mt-3 text-[13.5px] text-ink2"><b>{{ __('Fishery records') }}:</b> {{ $records }}</div>
                            @endif
                        </div>
                    @endif

                    @if (($rules = $page->anglerRules()) !== [] || $page->fishingMethods() !== [])
                        <div>
                            <h2 class="font-display text-[19px] font-semibold">{{ __('Before you come') }}</h2>
                            @if ($rules !== [])
                                @include('portal.partials.parameters', ['items' => $rules])
                            @endif
                            @if (($methods = $page->fishingMethods()) !== [])
                                <p class="mt-2 text-[12.5px] text-muted">{{ __('Fishing methods') }}: {{ implode(', ', $methods) }}</p>
                            @endif
                            <p class="mt-1 text-[12.5px] text-muted">{{ __('Equipment, groundbait and photo rules are in the fishery\'s terms.') }}</p>
                        </div>
                    @endif

                    @php($directions = $page->html($fishery->directions))
                    @php($conveniences = $page->conveniences())
                    @if ($directions !== null || $conveniences !== [])
                        <div>
                            <h2 class="font-display text-[19px] font-semibold">{{ __('Access and amenities') }}</h2>
                            <div class="mt-2.5 grid gap-4 md:grid-cols-2">
                                @if ($directions !== null)
                                    <div class="rounded-lg border border-line bg-white px-4 py-3.5">
                                        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">{{ __('Directions') }}</div>
                                        <div class="mt-1.5 text-[13px]">{{ $directions }}</div>
                                    </div>
                                @endif
                                @if ($conveniences !== [])
                                    <div class="rounded-lg border border-line bg-white px-4 py-3.5">
                                        <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">{{ __('Amenities') }}</div>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach ($conveniences as $convenience)
                                                <span class="rounded-full bg-line2 px-2.5 py-0.5 text-[11.5px] font-semibold text-muted">{{ $convenience }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if (($positions = $page->positions()) !== [])
                        <div>
                            <h2 class="font-display text-[19px] font-semibold">{{ __('Positions') }}</h2>
                            {{-- Jedno stanowisko w wierszu: opis (grupy, pojemność, cechy) bywa długi, dwie kolumny go ściskały. --}}
                            <ul class="mt-2.5">
                                @foreach ($positions as $position)
                                    <li class="flex justify-between gap-3 border-b border-line2 px-0.5 py-1.5 text-[13px]">
                                        <b class="shrink-0">{{ __('Pos. :label', ['label' => $position['label']]) }}</b>
                                        @if ($position['details'] !== '')
                                            <span class="text-right text-muted">{{ $position['details'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if (($groups = $page->groups()) !== [])
                                <div class="mt-3 flex flex-col gap-1.5 text-[12.5px]">
                                    @foreach ($groups as $group)
                                        <div class="rounded-sm border border-line bg-surface px-3 py-2">
                                            <b>{{ $group['name'] }}</b> · {{ $group['positions'] }}
                                            @if ($group['description'] !== null)
                                                <div class="prose-portal mt-0.5 text-ink2">{{ $group['description'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div>
                        <h2 class="font-display text-[19px] font-semibold">{{ __('The fishery online') }}</h2>
                        <div class="mt-2.5 flex flex-wrap gap-x-7 gap-y-3 rounded-lg border border-line bg-white px-4 py-3.5 text-[13.5px]">
                            {{-- Kolejność: adres w Fisherya, strona WWW łowiska, Facebook — dwa ostatnie tylko wypełnione. --}}
                            <div>
                                <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">{{ __('Address on Fisherya') }}</div>
                                <a href="{{ url($fishery->slug) }}" class="font-semibold text-b700 hover:underline">{{ $page->shortAddress() }}</a>
                            </div>
                            @if (filled($fishery->website_url))
                                <div>
                                    <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">{{ __('Fishery website') }}</div>
                                    <a href="{{ $fishery->website_url }}" target="_blank" rel="noopener" class="font-semibold text-b700 hover:underline">{{ \App\Services\PortalFisheryPage::displayUrl($fishery->website_url) }} ↗</a>
                                </div>
                            @endif
                            @if (filled($fishery->facebook_url))
                                <div>
                                    <div class="text-[11px] font-semibold uppercase tracking-[.09em] text-faint">Facebook</div>
                                    <a href="{{ $fishery->facebook_url }}" target="_blank" rel="noopener" class="font-semibold text-b700 hover:underline">{{ \App\Services\PortalFisheryPage::displayUrl($fishery->facebook_url) }} ↗</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <aside>
                    @include('portal.partials.fishery-box')
                </aside>
            </section>
        </div>

        {{-- Zdjęcia na końcu strony — tylko na telefonie (zjadałyby pierwszy ekran). --}}
        <div class="mt-2 lg:hidden">
            @include('portal.partials.fishery-photos', ['photoCount' => $photoCount])
        </div>

        @if ($phoneHref !== null)
            <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 px-4 py-2.5 backdrop-blur lg:hidden">
                <a href="{{ $phoneHref }}" class="flex w-full items-center justify-center gap-2 rounded-sm bg-b800 px-3 py-2.5 text-[14px] font-semibold text-white">
                    {{ __('Call') }} · {{ $fishery->phone }}
                </a>
            </div>
        @endif
    </article>
@endsection
