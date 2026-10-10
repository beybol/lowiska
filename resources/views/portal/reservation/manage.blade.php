{{-- Rezygnacja z rezerwacji albo „to nie ja" — strona pod tokenem z maila (zadanie 043, makieta H). --}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', __('Cancel the reservation?'))
@section('description', __('Cancel the reservation?'))

@section('content')
    <section class="border-b border-line bg-white">
        <div class="mx-auto max-w-3xl px-4 pt-10 pb-7 sm:px-6">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $fishery->name }}</p>
            <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight">{{ __('Cancel the reservation?') }}</h1>
        </div>
    </section>

    <section class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 text-[15px] text-ink2 sm:px-6">
        <div class="rounded-md border border-line bg-surface px-5 py-4">
            <div class="font-semibold text-ink">{{ $reservation->terms?->position_snapshot['name'] ?? $reservation->position?->name }}</div>
            <div>{{ $reservation->stayLabel() }}</div>
        </div>

        <form method="POST" action="{{ route(request()->route()->getName().'.submit', ['token' => $token]) }}" class="flex flex-col gap-4">
            @csrf
            <label class="flex flex-col gap-1">
                <span class="font-semibold text-ink">{{ __('Reason (optional)') }}</span>
                <textarea name="reason" rows="3" maxlength="1000" class="rounded-md border border-line bg-white px-3 py-2"></textarea>
            </label>
            <div class="flex flex-wrap gap-3">
                <button type="submit" name="action" value="resign" class="rounded-md bg-err px-5 py-2.5 font-semibold text-white">{{ __('Cancel the reservation') }}</button>
                <button type="submit" name="action" value="not_me" class="rounded-md border border-line bg-white px-5 py-2.5 font-semibold text-ink">{{ __('This is not me') }}</button>
            </div>
            <p class="text-sm text-muted">{{ __('"This is not me" withdraws the request — the fishery will check the reservation, and your address will not receive further messages about it.') }}</p>
        </form>
    </section>
@endsection
