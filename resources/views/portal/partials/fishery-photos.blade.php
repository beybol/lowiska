{{--
    Zdjęcia łowiska — w zadaniu 032 ZAŚLEPKI z makiety (1 duże + 2 małe); prawdziwa galeria: 036.
    Etykieta „Wszystkie zdjęcia · N" tylko przy N > 0 (liczba zdjęć w galerii łowiska).
--}}
<div class="mx-auto grid h-44 max-w-6xl grid-cols-[2fr_1fr_1fr] gap-1.5 p-1.5 sm:h-52" aria-hidden="true">
    <div class="relative rounded-l-md bg-[linear-gradient(145deg,var(--color-b600),var(--color-b900))]">
        <div class="absolute inset-0 opacity-30 bg-[radial-gradient(ellipse_120px_24px_at_30%_62%,transparent_46%,rgba(255,255,255,.55)_48%,transparent_52%),radial-gradient(ellipse_170px_30px_at_62%_78%,transparent_48%,rgba(255,255,255,.36)_50%,transparent_54%)]"></div>
    </div>
    <div class="bg-[linear-gradient(145deg,var(--color-b500),var(--color-b800))]"></div>
    <div class="relative rounded-r-md bg-[linear-gradient(145deg,var(--color-b300),var(--color-b600))]">
        @if ($photoCount > 0)
            <span class="absolute right-2 bottom-2 rounded-full bg-black/55 px-2.5 py-0.5 text-[11.5px] font-semibold text-white">{{ __('All photos · :count', ['count' => $photoCount]) }}</span>
        @endif
    </div>
</div>
