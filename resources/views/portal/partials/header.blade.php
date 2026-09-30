{{--
    Nagłówek portalu (portal-v3 §1.1): znak, „Łowiska", „Jak to działa", „Dla łowisk", PL · EN.
    Bez „Zaloguj / Załóż konto" do etapu 4. Na telefonie znak i menu ☰ — `<details>`, bez skryptu.
--}}
@php($links = [
    ['url' => \App\Services\PortalRoutes::homeUrl(), 'label' => __('Fisheries')],
    ['url' => \App\Services\PortalRoutes::pageUrl('how-it-works'), 'label' => __('How it works')],
    ['url' => \App\Services\PortalRoutes::pageUrl('for-fisheries'), 'label' => __('For fisheries')],
])
<header class="border-b border-line bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 lg:py-4">
        <a href="{{ \App\Services\PortalRoutes::homeUrl() }}" class="flex items-center gap-3" aria-label="Fisherya">
            <img src="{{ asset('favicon.svg') }}" alt="" class="size-7" width="28" height="28">
            <span class="font-display text-[19px] font-semibold leading-none tracking-tight text-b900">fisherya</span>
        </a>

        <nav class="hidden items-center gap-6 text-[13.5px] font-medium text-ink2 md:flex" aria-label="{{ __('Portal') }}">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" class="hover:text-b700">{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="hidden text-[12.5px] text-muted md:block">
            @include('portal.partials.language-switch')
        </div>

        <details class="group relative md:hidden">
            <summary class="flex size-10 cursor-pointer list-none items-center justify-center rounded-sm text-xl text-ink2 hover:bg-b50 [&::-webkit-details-marker]:hidden" aria-label="{{ __('Menu') }}">☰</summary>
            <div class="absolute right-0 z-20 mt-2 w-60 rounded-md border border-line bg-white p-2 shadow-3">
                @foreach ($links as $link)
                    <a href="{{ $link['url'] }}" class="block rounded-sm px-3 py-2.5 text-[15px] font-medium text-ink2 hover:bg-b50 hover:text-b700">{{ $link['label'] }}</a>
                @endforeach
                <div class="mt-1 border-t border-line2 px-3 pt-3 pb-1 text-[13px] text-muted">
                    @include('portal.partials.language-switch')
                </div>
            </div>
        </details>
    </div>
</header>
