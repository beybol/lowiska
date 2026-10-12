{{-- Potwierdzenie rezerwacji — strona pod tokenem z maila (zadania 043 i 049, makieta H). Potwierdzenie NIE wymaga konta. --}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', __('Confirm your reservation'))
@section('description', __('Confirm your reservation'))

@section('content')
    <section class="border-b border-line bg-white">
        <div class="mx-auto max-w-3xl px-4 pt-10 pb-7 sm:px-6">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $fishery->name }}</p>
            <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight">{{ __('Confirm your reservation') }}</h1>
        </div>
    </section>

    <section class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 text-[15px] text-ink2 sm:px-6">
        @forelse ($reservations as $reservation)
            <div class="rounded-md border border-line bg-surface px-5 py-4">
                <div class="font-semibold text-ink">{{ $reservation->terms?->position_snapshot['name'] ?? $reservation->position?->name }}</div>
                <div>{{ $reservation->stayLabel() }}</div>
                @if ($reservation->terms)
                    <div class="text-muted">{{ \App\Services\AmountFormatter::forVisitor($reservation->terms->total_cents, $reservation->terms->currency) }}</div>
                @endif
            </div>
        @empty
            <p class="text-muted">{{ __('There is no reservation waiting for confirmation.') }}</p>
        @endforelse

        @if ($pending)
            <form method="POST" action="{{ route(request()->route()->getName().'.submit', ['token' => $token]) }}">
                @csrf
                <button type="submit" name="action" value="confirm" class="rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Confirm reservation') }}</button>
                <p class="mt-2 text-sm text-muted">{{ __('No account is needed to confirm.') }}</p>
            </form>
        @else
            @include('portal.reservation.partials.account-invite', ['hasAccount' => $hasAccount, 'token' => $token])
        @endif
    </section>
@endsection
