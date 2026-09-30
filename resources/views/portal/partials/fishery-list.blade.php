{{-- Lista łowisk opublikowanych, alfabetycznie — strona główna i 404 portalu. --}}
<div class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
    <h2 class="font-display text-[22px] font-semibold tracking-tight">{{ $heading ?? __('Fisheries in the portal') }}</h2>
    <span class="text-[12.5px] text-muted">
        {{ trans_choice(':count fishery|:count fisheries', count($cards), ['count' => count($cards)]) }}
        · {{ __('alphabetically') }} ·
        <a href="{{ \App\Services\PortalRoutes::pageUrl('listing-rules') }}" class="font-semibold text-b700 hover:underline">{{ __('How we order the list') }}</a>
    </span>
</div>

@if ($cards === [])
    <p class="rounded-md border border-line bg-white px-5 py-6 text-muted">{{ __('No fishery is published yet.') }}</p>
@else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($cards as $card)
            @include('portal.partials.fishery-card', ['card' => $card])
        @endforeach
    </div>
@endif
