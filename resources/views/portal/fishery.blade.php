{{--
    Strona łowiska pod adresem kanonicznym — w zadaniu 031 ZAŚLEPKA (nazwa w układzie portalu).
    Treść: zadanie 032 (zdjęcia, zakładki „Mapa i terminy" / „Szczegóły", box z ceną i kontaktem).
--}}
@extends('portal.layout')

@section('title', $fishery->name)
@section('description', __(':fishery — fishery rules, prices and dates.', ['fishery' => $fishery->name]))

@section('content')
    <section class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <h1 class="font-display text-[30px] font-semibold tracking-tight sm:text-[36px]">{{ $fishery->name }}</h1>
        <p class="mt-1 text-muted">{{ __($fishery->state?->name ?? '') }}</p>
        <p class="mt-6 rounded-md border border-line bg-white px-5 py-6 text-muted">{{ __('The fishery page is being prepared.') }}</p>
    </section>
@endsection
