{{--
    Sekcja „Zdjęcia" na końcu strony łowiska — tylko na telefonie (036): 2 kafelki, na drugim „+N"
    przy więcej niż dwóch zdjęciach; ten sam podgląd pełnoekranowy co nagłówek. Brak zdjęć — brak sekcji (R3).
--}}
@if ($photos !== [])
    @php($count = count($photos))
    <section class="px-4 pb-4">
        <h2 class="font-display text-[19px] font-semibold">{{ __('Photos') }}</h2>
        <div class="mt-2.5 grid grid-cols-2 gap-1.5" data-gallery>
            @foreach ($photos as $index => $photo)
                <a href="{{ $photo['full'] }}" data-pswp-width="{{ $photo['width'] }}" data-pswp-height="{{ $photo['height'] }}"
                   @class(['relative block aspect-[4/3] overflow-hidden rounded-sm bg-b100', 'col-span-2' => $count === 1, 'hidden' => $index > 1])
                   aria-label="{{ $photo['alt'] }}">
                    @if ($index < 2)
                        <img src="{{ $photo['src'] }}" srcset="{{ $photo['srcset'] }}" sizes="50vw"
                             alt="{{ $photo['alt'] }}" width="{{ $photo['width'] }}" height="{{ $photo['height'] }}"
                             loading="lazy" decoding="async" class="h-full w-full object-cover">
                        @if ($index === 1 && $count > 2)
                            <span class="absolute inset-0 flex items-center justify-center bg-black/45 font-display text-[22px] font-semibold text-white">+{{ $count - 2 }}</span>
                        @endif
                    @endif
                </a>
            @endforeach
        </div>
    </section>
@endif
