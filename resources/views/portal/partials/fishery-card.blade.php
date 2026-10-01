{{--
    Karta łowiska na liście (portal-v3 §5.1). Dane: `PortalFisheries::card()`.

    ⚠️ Okładka = pierwsze zdjęcie galerii, wyłącznie warianty z `FisheryImages` (036); bez zdjęcia — neutralne
    tło motywu, bez zaślepki udającej zdjęcie (R3).
    ⚠️ „Cenę od" podaje `PriceFrom` (032), linijkę zasad — `FisheryRulesSummary::cardLine()` (033);
    karta nie pokazuje usług.
--}}
<a href="{{ $card['url'] }}" class="group block overflow-hidden rounded-md border border-line bg-white transition hover:border-b300 hover:shadow-2">
    <div class="relative h-36 bg-b100" aria-hidden="true">
        @if ($card['cover'] !== null)
            <img src="{{ $card['cover']['src'] }}" srcset="{{ $card['cover']['srcset'] }}"
                 sizes="(min-width: 1024px) 360px, (min-width: 640px) 50vw, 100vw"
                 alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
        @endif
    </div>
    <div class="px-4 py-3.5">
        <h3 class="text-[15px] font-semibold text-ink group-hover:text-b700">{{ $card['name'] }}</h3>
        <p class="mt-0.5 text-[12.5px] text-muted">
            {{ implode(' · ', array_filter([
                $card['water'],
                $card['state'],
                trans_choice(':count position|:count positions', $card['positions'], ['count' => $card['positions']]),
            ])) }}
        </p>
        @if ($card['rules'] !== '')
            <p class="mt-2 text-[12px] text-ink2">{{ $card['rules'] }}</p>
        @endif
        <div class="mt-3 flex items-end justify-between border-t border-line2 pt-3">
            <div class="text-[11.5px] text-muted">
                @if ($card['price_from'] !== null)
                    <b class="font-display text-[17px] font-semibold text-ink">{{ __('from :price', ['price' => $card['price_from']]) }}</b> {{ __('/ person / night') }}
                @else
                    <span class="font-semibold">{{ __('Price list in preparation') }}</span>
                @endif
            </div>
            @if ($card['no_kill'])
                <span class="inline-flex items-center rounded-full bg-line2 px-2.5 py-0.5 text-[11.5px] font-semibold text-muted">no-kill</span>
            @endif
        </div>
    </div>
</a>
