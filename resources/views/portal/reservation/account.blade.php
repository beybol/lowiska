{{--
    Łączenie konta z łowiskiem (zadanie 049) — strona bez tokenu w adresie, relacja ze znacznika w sesji.
    Tryby (`$mode`): `sign-in` · `register` · `link` · `wrong-account` (`ReservationLinkController::mode()`).
    ⚠️ Adres konta to adres z prośby — wyświetlany, nigdy wysyłany w formularzu.
--}}
@extends('portal.layout', ['noCanonical' => true])

@section('title', __('Link your account'))
@section('description', __('Link your account'))

@section('content')
    <section class="border-b border-line bg-white">
        <div class="mx-auto max-w-3xl px-4 pt-10 pb-7 sm:px-6">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $fishery->name }}</p>
            <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight">{{ __('Link your account') }}</h1>
            <p class="mt-2 text-muted">{{ __('Account address: :email', ['email' => $email]) }}</p>
        </div>
    </section>

    <section class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 text-[15px] text-ink2 sm:px-6">
        @php
            $action = route(request()->route()->getName().'.submit');
        @endphp

        @if ($mode === 'sign-in')
            <p>{{ __('You already have a Fisherya account with this address. Sign in to link it with this fishery — you will come back here after signing in.') }}</p>
            <div class="flex flex-wrap items-center gap-4">
                <a href="{{ route('login') }}" class="rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Sign in') }}</a>
                <a href="{{ route('social.redirect', 'google') }}" class="rounded-md border border-line bg-white px-5 py-2.5 font-semibold text-ink">{{ __('Sign in with Google') }}</a>
            </div>
        @elseif ($mode === 'register')
            <p>{{ __('Create a Fisherya account with the address the fishery sent the reservation to. The click in the email has already confirmed it.') }}</p>

            <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="action" value="register">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-1">
                        <span class="font-semibold text-ink">{{ __('Name') }}</span>
                        <input type="text" name="name" value="{{ old('name', \Illuminate\Support\Str::before(trim((string) $angler->given_name), ' ')) }}" required maxlength="255" autocomplete="given-name" class="rounded-md border border-line bg-white px-3 py-2">
                        @error('name')<span class="text-sm text-err">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="font-semibold text-ink">{{ __('Surname') }}</span>
                        <input type="text" name="surname" value="{{ old('surname', \Illuminate\Support\Str::contains(trim((string) $angler->given_name), ' ') ? \Illuminate\Support\Str::after(trim((string) $angler->given_name), ' ') : '') }}" required maxlength="255" autocomplete="family-name" class="rounded-md border border-line bg-white px-3 py-2">
                        @error('surname')<span class="text-sm text-err">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="font-semibold text-ink">{{ __('Password') }}</span>
                        <input type="password" name="password" required autocomplete="new-password" class="rounded-md border border-line bg-white px-3 py-2">
                        @error('password')<span class="text-sm text-err">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="font-semibold text-ink">{{ __('Confirm password') }}</span>
                        <input type="password" name="password_confirmation" required autocomplete="new-password" class="rounded-md border border-line bg-white px-3 py-2">
                    </label>
                </div>

                @include('portal.reservation.partials.consents', ['documents' => $platformDocuments, 'heading' => __('Fisherya documents')])
                @include('portal.reservation.partials.consents', ['documents' => $fisheryDocuments, 'heading' => __('Documents of :fishery', ['fishery' => $fishery->name])])

                <button type="submit" class="self-start rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Create an account and link') }}</button>
            </form>

            <div class="flex flex-wrap items-center gap-3 text-muted">
                <span>{{ __('or') }}</span>
                <a href="{{ route('social.redirect', 'google') }}" class="rounded-md border border-line bg-white px-5 py-2.5 font-semibold text-ink">{{ __('Continue with Google') }}</a>
                <span class="text-sm">{{ __('The Google account must use the same address.') }}</span>
            </div>
        @elseif ($mode === 'link')
            <p>{{ __('You are signed in with the address the fishery sent the reservation to.') }}</p>
            <p class="text-muted">{{ __('After linking, the fishery sees your name, email address and phone number from your Fisherya account — and nothing else.') }}</p>

            <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="action" value="link">

                @if ($phoneConflict)
                    <fieldset class="rounded-md border border-line bg-white px-5 py-4">
                        <legend class="px-1 font-semibold text-ink">{{ __('Which phone number should the fishery use?') }}</legend>
                        <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="profile" checked> {{ __('The number from my account: :phone', ['phone' => $accountPhone]) }}</label>
                        <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="save"> {{ __('The new number :phone — and save it in my account', ['phone' => $angler->given_phone]) }}</label>
                        <label class="mt-2 flex gap-2"><input type="radio" name="phone" value="fishery"> {{ __('The new number :phone — only for this fishery', ['phone' => $angler->given_phone]) }}</label>
                    </fieldset>
                @endif

                @include('portal.reservation.partials.consents', ['documents' => $fisheryDocuments, 'heading' => __('Documents of :fishery', ['fishery' => $fishery->name])])

                <button type="submit" class="self-start rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Link my account') }}</button>
            </form>
        @else
            <div class="rounded-md border border-line bg-surface px-5 py-4">
                <p class="font-semibold text-ink">{{ __('You are signed in with a different account.') }}</p>
                <p class="mt-1 text-muted">{{ __('The reservation can only be linked with the account that uses :email. Sign out and sign in with that account.', ['email' => $email]) }}</p>
                <form method="POST" action="{{ $action }}" class="mt-4">
                    @csrf
                    <button type="submit" name="action" value="logout" class="rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">{{ __('Sign out') }}</button>
                </form>
            </div>
        @endif

        <a href="{{ \App\Services\PortalRoutes::homeUrl() }}" class="self-start font-semibold text-b600 hover:underline">{{ __('Not now') }}</a>
    </section>
@endsection
