{{--
    Landing „Dla łowisk" (portal-v3 §1, makieta „Dla łowisk"). Wejście WYŁĄCZNIE z nawigacji i stopki —
    strona główna jest dla wędkarzy. Jedno wezwanie do działania: e-mail i telefon, BEZ formularza
    (mniej danych do przechowywania). Telefon pokazuje się dopiero, gdy jest w `config/portal.php`.
--}}
@extends('portal.layout')

@section('title', __('For fisheries'))
@section('description', __('Your fishery, positions and sale rules in one place. We configure the fishery for you.'))

@section('content')
    @php
        $email = config('portal.contact_email');
        $phone = config('portal.contact_phone');
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'fisherya.com';
    @endphp
    <section class="relative overflow-hidden bg-[linear-gradient(160deg,var(--color-b900)_0%,var(--color-b800)_46%,var(--color-b700)_100%)] text-white">
        <div class="relative z-10 mx-auto max-w-6xl px-4 pt-10 pb-12 sm:px-6 lg:pt-14 lg:pb-16">
            <h1 class="max-w-[18ch] font-display text-[30px] font-semibold leading-[1.1] tracking-tight sm:text-[41px]">{{ __('Your fishery, positions and sale rules in one place') }}</h1>
            <p class="mt-3.5 max-w-[56ch] text-[16px] text-b200">{{ __('We configure the fishery for you: the fishing day, seasons, weekends sold whole, prices with surcharges, services. Before anything is shown to anglers, you see in the calendar what it all adds up to.') }}</p>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                <a href="mailto:{{ $email }}" class="inline-flex items-center justify-center rounded-md bg-a500 px-6 py-3.5 text-[15.5px] font-semibold text-a800 transition hover:bg-a400">{{ __('Write to us') }}</a>
                @if (filled($phone))
                    <span class="text-[14px] text-b200">{{ __('or call') }}: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="font-semibold text-white">{{ $phone }}</a></span>
                @else
                    <span class="text-[14px] text-b200">{{ $email }}</span>
                @endif
            </div>
        </div>
        <div class="pointer-events-none absolute inset-x-[-40px] bottom-[-70px] h-48 bg-[radial-gradient(ellipse_at_50%_0%,var(--color-b500),transparent_66%)] opacity-40" aria-hidden="true"></div>
    </section>

    @if ($fisheryNames !== [])
        <div class="bg-b950 text-[12.5px] text-b300">
            <div class="mx-auto max-w-6xl px-4 py-3.5 sm:px-6">
                <b class="font-semibold text-b100">{{ __('First fisheries in the portal') }}:</b> {{ implode(' · ', $fisheryNames) }}
            </div>
        </div>
    @endif

    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-10">
        <h2 class="font-display text-[22px] font-semibold tracking-tight">{{ __('How onboarding works') }}</h2>
        <ol class="mt-4 grid gap-3.5 md:grid-cols-3">
            @foreach ([
                [__('We configure it for you'), __('From your price list and rules we set up positions, seasons, stay rules and prices.')],
                [__('You check it in the calendar'), __('The preview calendar shows night by night what can be sold and for how much — before an angler sees it.')],
                [__('You get your page address'), __('A short address, e.g. :address, goes on your banner, your fanpage and over the phone. Anglers book with you just as they do now.', ['address' => $host.'/'.__('your-fishery')])],
            ] as $index => [$title, $text])
                <li class="rounded-md border border-line bg-white p-4">
                    <span class="block font-display text-[26px] font-semibold text-b700">{{ $index + 1 }}</span>
                    <span class="font-semibold">{{ $title }}</span>
                    <p class="mt-1.5 text-[13px] text-muted">{{ $text }}</p>
                </li>
            @endforeach
        </ol>

        <h2 class="mt-8 font-display text-[22px] font-semibold tracking-tight">{{ __('What comes next') }}</h2>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-line bg-white p-5">
                <span class="inline-flex rounded-full bg-b100 px-2.5 py-0.5 text-[11.5px] font-semibold text-b800">{{ __('NEXT') }}</span>
                <p class="mt-2 text-[13.5px]">{{ __('Bookings in the panel instead of a notebook and a hand-drawn schedule — and anglers see real availability.') }}</p>
            </div>
            <div class="rounded-lg border border-line bg-white p-5">
                <span class="inline-flex rounded-full bg-a100 px-2.5 py-0.5 text-[11.5px] font-semibold text-a700">{{ __('LATER') }}</span>
                <p class="mt-2 text-[13.5px]">{{ __('Online sales: the angler pays in advance, the money goes to the fishery, and you set the refund rules yourself.') }}</p>
            </div>
        </div>
    </section>
@endsection
