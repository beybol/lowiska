{{-- Potwierdzenie rezerwacji i powiązanie z kontem — strona pod tokenem z maila (zadanie 043, makieta H). --}}
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
            <p class="text-muted">{{ __('There is no active reservation to confirm, but you can still link this fishery with your account.') }}</p>
        @endforelse

        <div>
            <h2 class="font-display text-lg font-semibold text-ink">{{ __('What the fishery will see') }}</h2>
            <p class="mt-1 text-muted">{{ __('After linking, the fishery sees your name, email address and phone number from your Fisherya account — and nothing else.') }}</p>
        </div>

        <form method="POST" action="{{ route(request()->route()->getName().'.submit', ['token' => $token]) }}" class="flex flex-col gap-4">
            @csrf

            @if ($phoneConflict)
                <fieldset class="rounded-md border border-line bg-white px-5 py-4">
                    <legend class="px-1 font-semibold text-ink">{{ __('Which phone number should the fishery use?') }}</legend>
                    <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="profile" checked> {{ __('The number from my account: :phone', ['phone' => $accountPhone]) }}</label>
                    <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="save"> {{ __('The new number :phone — and save it in my account', ['phone' => $angler->given_phone]) }}</label>
                    <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="fishery"> {{ __('The new number :phone — only for this fishery', ['phone' => $angler->given_phone]) }}</label>
                </fieldset>
            @endif

            <button type="submit" class="self-start rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Confirm') }}</button>
        </form>
    </section>
@endsection
