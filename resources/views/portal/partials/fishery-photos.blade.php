{{--
    Nagłówek zdjęć strony łowiska (desktop) — zadanie 036.

    Układ wg liczby zdjęć: ≥ 3 — 1 duże + 2 małe i etykieta „Wszystkie zdjęcia · N"; 2 — po połowie;
    1 — pełna szerokość; 0 — nagłówka nie ma (R3). Kafelki mają stałą wysokość i `object-cover`, więc układ
    nie skacze przy ładowaniu. Każdy kafelek otwiera podgląd pełnoekranowy (PhotoSwipe, R2) od swojego zdjęcia;
    zdjęcia spoza kafelków są w galerii jako ukryte odsyłacze, żeby podgląd liczył „3 / 12".

    ⚠️ Wyłącznie warianty z `FisheryImages` (`PortalFisheryPage::photos()`) — oryginał nigdy nie trafia do HTML-a.
    Pierwszy ekran: pierwsze zdjęcie bez `loading="lazy"`.
--}}
@if ($photos !== [])
    @php
        $count = count($photos);
        $tiles = array_slice($photos, 0, 3);
        $grid = match (true) {
            $count >= 3 => 'grid-cols-[2fr_1fr_1fr]',
            $count === 2 => 'grid-cols-2',
            default => 'grid-cols-1',
        };
    @endphp
    <div class="mx-auto grid h-44 max-w-6xl {{ $grid }} gap-1.5 p-1.5 sm:h-52" data-gallery>
        @foreach ($photos as $index => $photo)
            @php
                $isTile = $index < 3;
                $rounded = match (true) {
                    $count === 1 => 'rounded-md',
                    $index === 0 => 'rounded-l-md',
                    $index === count($tiles) - 1 => 'rounded-r-md',
                    default => '',
                };
            @endphp
            <a href="{{ $photo['full'] }}" data-pswp-width="{{ $photo['width'] }}" data-pswp-height="{{ $photo['height'] }}"
               @class(['relative block overflow-hidden bg-b100', $rounded, 'hidden' => ! $isTile])
               aria-label="{{ $photo['alt'] }}">
                @if ($isTile)
                    <img src="{{ $photo['src'] }}" srcset="{{ $photo['srcset'] }}"
                         sizes="{{ $count >= 3 && $index > 0 ? '(min-width: 1152px) 280px, 25vw' : '(min-width: 1152px) 1152px, 100vw' }}"
                         alt="{{ $photo['alt'] }}" width="{{ $photo['width'] }}" height="{{ $photo['height'] }}"
                         @if ($index > 0) loading="lazy" @endif decoding="async"
                         class="h-full w-full object-cover">
                    @if ($count >= 3 && $index === 2)
                        <span class="absolute right-2 bottom-2 rounded-full bg-black/55 px-2.5 py-0.5 text-[11.5px] font-semibold text-white">{{ __('All photos · :count', ['count' => $count]) }}</span>
                    @endif
                @endif
            </a>
        @endforeach
    </div>
@endif
