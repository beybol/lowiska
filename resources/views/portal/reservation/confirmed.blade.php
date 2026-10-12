{{-- Rezerwacja potwierdzona linkiem z maila, bez konta (zadanie 049) — z zachętą do połączenia z kontem. --}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', __('Reservation confirmed'))
@section('description', __('Reservation confirmed'))

@section('content')
    <section class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-12 sm:px-6">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $fishery->name }}</p>
            <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight">{{ __('Reservation confirmed') }}</h1>
            <p class="mt-3 max-w-[60ch] text-ink2">{{ __('Thank you. The fishery sees that you confirmed the reservation.') }}</p>
        </div>

        @include('portal.reservation.partials.account-invite', ['hasAccount' => $hasAccount, 'token' => $token])
    </section>
@endsection
