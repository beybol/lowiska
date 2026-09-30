{{--
    Box z „ceną od" i kontaktem — ten sam w obu zakładkach strony łowiska (zadanie 032).
    Bez telefonu: e-mail łowiska albo nic — bez tekstu zastępczego (Rozstrzygnięcie 3).
--}}
@php
    $price = $page->priceFrom();
    $phoneHref = $page->phoneHref();
@endphp
<div class="rounded-md border border-line bg-surface px-4 py-4">
    <div class="text-[13px] text-ink2">
        @if ($price !== null)
            <b class="font-display text-[19px] font-semibold text-ink">{{ __('from :price', ['price' => $price]) }}</b> {{ __('/ person / night') }}
        @else
            <span class="font-semibold text-ink">{{ __('Price list in preparation') }}</span>
        @endif
    </div>

    @if ($phoneHref !== null)
        <div class="mt-3.5 text-[10.5px] font-bold uppercase tracking-[.1em] text-b700">{{ __('Bookings by phone') }}</div>
        <div class="mt-0.5 text-[15px] font-semibold">{{ $fishery->phone }}</div>
        @if (filled($fishery->contact_hours))
            <div class="text-[12px] text-muted">{{ $fishery->contact_hours }}</div>
        @endif
        <a href="{{ $phoneHref }}" class="mt-3 flex w-full items-center justify-center rounded-sm bg-b800 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-b700">{{ __('Call') }}</a>
        <p class="mt-2.5 text-[11.5px] text-a700">{{ __('The fishery will confirm free dates.') }}</p>
    @elseif (filled($fishery->email))
        <div class="mt-3.5 text-[10.5px] font-bold uppercase tracking-[.1em] text-b700">{{ __('Contact') }}</div>
        <a href="mailto:{{ $fishery->email }}" class="mt-0.5 block break-all text-[14px] font-semibold text-b700 hover:underline">{{ $fishery->email }}</a>
        @if (filled($fishery->contact_hours))
            <div class="text-[12px] text-muted">{{ $fishery->contact_hours }}</div>
        @endif
    @endif

    @if (filled($fishery->website_url) || filled($fishery->facebook_url))
        <div class="mt-3 flex flex-wrap gap-3 border-t border-line pt-2.5 text-[12.5px] font-semibold text-b700">
            @if (filled($fishery->website_url))
                <a href="{{ $fishery->website_url }}" target="_blank" rel="noopener" class="hover:underline">{{ \App\Services\PortalFisheryPage::displayUrl($fishery->website_url) }} ↗</a>
            @endif
            @if (filled($fishery->facebook_url))
                <a href="{{ $fishery->facebook_url }}" target="_blank" rel="noopener" class="hover:underline">Facebook ↗</a>
            @endif
        </div>
    @endif
</div>
