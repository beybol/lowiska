{{--
    Karta łowiska na liście (portal-v3 §5.1). Dane: `PortalFisheries::card()`.

    ⚠️ Zdjęcie to ZAŚLEPKA z makiety (gradient z falą) — prawdziwe okładki dochodzą w 036.
    ⚠️ Bez „ceny od" (032) i linijki zasad (033); karta nie pokazuje usług.
--}}
<a href="{{ $card['url'] }}" class="group block overflow-hidden rounded-md border border-line bg-white transition hover:border-b300 hover:shadow-2">
    <div class="relative h-36 bg-[linear-gradient(145deg,var(--color-b600),var(--color-b800))]" aria-hidden="true">
        <div class="absolute inset-0 opacity-30 bg-[radial-gradient(ellipse_120px_24px_at_30%_62%,transparent_46%,rgba(255,255,255,.55)_48%,transparent_52%),radial-gradient(ellipse_170px_30px_at_62%_78%,transparent_48%,rgba(255,255,255,.36)_50%,transparent_54%)]"></div>
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
        @if ($card['no_kill'])
            <div class="mt-3 flex justify-end border-t border-line2 pt-3">
                <span class="inline-flex items-center rounded-full bg-line2 px-2.5 py-0.5 text-[11.5px] font-semibold text-muted">no-kill</span>
            </div>
        @endif
    </div>
</a>
