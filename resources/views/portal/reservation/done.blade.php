{{-- Wynik albo komunikat strony pod tokenem (zadanie 043): potwierdzono, zrezygnowano, link wygasł albo zużyty. --}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', $heading)
@section('description', $heading)

@section('content')
    <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        @if ($fishery)
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $fishery->name }}</p>
        @endif
        <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight">{{ $heading }}</h1>
        <p class="mt-3 max-w-[60ch] text-ink2">{{ $message }}</p>
        @if ($fishery && ($fishery->phone || $fishery->email))
            <p class="mt-3 text-muted">{{ __('Contact the fishery') }}: {{ collect([$fishery->phone, $fishery->email])->filter()->implode(' · ') }}</p>
        @endif
        <a href="{{ \App\Services\PortalRoutes::homeUrl() }}" class="mt-6 inline-block font-semibold text-b600 hover:underline">{{ __('Go to Fisherya') }}</a>
    </section>
@endsection
