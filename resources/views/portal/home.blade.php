{{--
    Strona główna portalu — dla WĘDKARZA (portal-v3 §1, §5.1): hasło i lista wszystkich łowisk
    opublikowanych, alfabetycznie. Świadomie bez wyszukiwarki, mapy, filtrów i banera dla łowisk
    (wracają po przekroczeniu 12 łowisk, §5.2).
--}}
@extends('portal.layout')

@section('title', __('Fishery rules, prices and dates'))
@section('description', __('Check a fishery\'s rules, prices and dates before you call. Position maps, a calendar with prices per person per night and sale rules — straight from the fishery.'))

@section('content')
    <section class="relative overflow-hidden bg-[linear-gradient(160deg,var(--color-b900)_0%,var(--color-b800)_46%,var(--color-b700)_100%)] text-white">
        <div class="relative z-10 mx-auto max-w-6xl px-4 pt-10 pb-12 sm:px-6 lg:pt-12 lg:pb-14">
            <h1 class="max-w-[20ch] font-display text-[30px] font-semibold leading-[1.1] tracking-tight sm:text-[41px]">{{ __('Check a fishery\'s rules, prices and dates before you call') }}</h1>
            <p class="mt-3.5 max-w-[52ch] text-[16px] text-b200">{{ __('Position maps, a calendar with prices per person per night and sale rules — straight from the fishery.') }}</p>
        </div>
        <div class="pointer-events-none absolute inset-x-[-40px] bottom-[-70px] h-48 bg-[radial-gradient(ellipse_at_50%_0%,var(--color-b500),transparent_66%)] opacity-40" aria-hidden="true"></div>
    </section>

    <div class="bg-b950 text-[12.5px] text-b300">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-3.5 sm:flex-row sm:gap-8 sm:px-6">
            <span><b class="font-semibold text-b100">{{ __('Prices') }}</b> {{ __('per person per night, straight from the fishery\'s price list') }}</span>
            <span><b class="font-semibold text-b100">{{ __('Sale rules') }}</b> {{ __('in the fishery\'s calendar') }}</span>
            <span><b class="font-semibold text-b100">{{ __('You book') }}</b> {{ __('directly with the fishery') }}</span>
        </div>
    </div>

    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-10">
        @include('portal.partials.fishery-list', ['cards' => $cards])
    </section>
@endsection
