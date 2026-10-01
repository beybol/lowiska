{{--
    Miniatury zdjęć łowiska w podglądzie „Dane łowiska" (zadanie 038, R5).

    ⚠️ Wyłącznie warianty z `FisheryImages` (ADR-023) — oryginał nie trafia do HTML-a. Galeria w kolejności
    z panelu, pierwsze zdjęcie oznaczone jako okładka. Brak zdjęć — „Nie podano", jak puste pole.
--}}
@if ($images === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Not specified') }}</p>
@else
    <div @class([
        'grid gap-3',
        'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' => $grid,
        'max-w-xl' => ! $grid,
    ])>
        @foreach ($images as $index => $image)
            <figure class="relative overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <img src="{{ $image['src'] }}" alt="{{ $image['alt'] }}" loading="lazy"
                     @class(['w-full object-cover', 'aspect-[4/3]' => $grid, 'h-auto' => ! $grid])>
                @if ($grid && $index === 0)
                    <figcaption class="absolute top-2 left-2 rounded-full bg-black/60 px-2 py-0.5 text-xs font-semibold text-white">{{ __('Cover') }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>
@endif
