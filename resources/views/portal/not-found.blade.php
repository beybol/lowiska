{{-- Strona 404 portalu — z listą łowisk, żeby nieznany adres nie był ślepą uliczką (zadanie 031). --}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', __('Page not found'))
@section('description', __('This page does not exist. See the fisheries in the portal.'))

@section('content')
    <section class="mx-auto max-w-6xl px-4 pt-10 pb-4 sm:px-6">
        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">404</p>
        <h1 class="mt-2 font-display text-[30px] font-semibold tracking-tight">{{ __('There is no such page') }}</h1>
        <p class="mt-2 max-w-[60ch] text-muted">{{ __('The address may be mistyped, or the fishery is no longer in the portal. Here are the fisheries you can see now.') }}</p>
    </section>

    <section class="mx-auto max-w-6xl px-4 pt-4 pb-10 sm:px-6">
        @include('portal.partials.fishery-list', ['cards' => $cards])
    </section>
@endsection
