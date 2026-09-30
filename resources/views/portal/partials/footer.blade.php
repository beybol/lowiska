{{--
    Stopka portalu (portal-v3 §1.1): desktop w pięciu kolumnach, telefon w dwóch.
    Stałe zdanie o stronie umowy — TODO-1 §2.1. Regulamin i polityka ŁOWISKA są na stronie łowiska.
--}}
@use('App\Services\PortalRoutes')
@php
    $groups = [
        __('Portal') => [
            [PortalRoutes::homeUrl(), __('Fisheries')],
            [PortalRoutes::pageUrl('how-it-works'), __('How it works')],
            [PortalRoutes::pageUrl('listing-rules'), __('How we order the list')],
        ],
        __('For fisheries') => [
            [PortalRoutes::pageUrl('for-fisheries'), __('Add your fishery')],
            [\Filament\Facades\Filament::getPanel('owner')->getUrl(), __('Fishery panel')],
        ],
        __('Rules') => [
            [PortalRoutes::pageUrl('terms'), __('Portal terms')],
            [PortalRoutes::pageUrl('privacy'), __('Privacy policy')],
            [PortalRoutes::pageUrl('cookies'), __('Cookies')],
            [PortalRoutes::pageUrl('report-content'), __('Report illegal content')],
        ],
    ];
    $email = config('portal.contact_email');
@endphp
<footer class="bg-b950 text-[12.5px] text-b300">
    <div class="mx-auto max-w-6xl px-4 pt-8 pb-5 sm:px-6">
        <div class="grid grid-cols-2 gap-x-6 gap-y-7 lg:grid-cols-[1.6fr_repeat(4,1fr)]">
            <div class="col-span-2 lg:col-span-1">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="size-6" width="24" height="24">
                    <span class="font-display text-[17px] font-semibold leading-none tracking-tight text-white">fisherya</span>
                </div>
                <p class="mt-3 max-w-[34ch] text-[12px] text-b300">{{ __('Offers in the portal come from fisheries — businesses. The fishery is the party to the contract; Fisherya acts as an intermediary.') }}</p>
            </div>

            @foreach ($groups as $heading => $links)
                <div>
                    <h2 class="mb-2.5 text-[10.5px] font-bold uppercase tracking-[.12em] text-b500">{{ $heading }}</h2>
                    <ul class="space-y-1.5">
                        @foreach ($links as [$url, $label])
                            <li><a href="{{ $url }}" class="text-b200 hover:text-white">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <div>
                <h2 class="mb-2.5 text-[10.5px] font-bold uppercase tracking-[.12em] text-b500">{{ __('Contact') }}</h2>
                <ul class="space-y-1.5 text-b200">
                    <li><a href="mailto:{{ $email }}" class="hover:text-white">{{ $email }}</a></li>
                    <li><a href="{{ PortalRoutes::pageUrl('contact') }}" class="hover:text-white">{{ __('Contact') }}</a></li>
                    <li>@include('portal.partials.language-switch', ['linkClass' => 'text-b400 hover:text-white'])</li>
                </ul>
            </div>
        </div>

        <div class="mt-6 border-t border-white/10 pt-3 text-[11.5px] text-b500">
            © {{ now()->year }} Fisherya · {{ __('company details in the portal terms') }}
        </div>
    </div>
</footer>
