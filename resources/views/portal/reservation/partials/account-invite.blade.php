{{--
    Zachęta do połączenia z kontem po potwierdzeniu rezerwacji (zadanie 049). „Połącz konto" zapisuje znacznik
    w sesji i prowadzi na stronę łączenia; „Nie teraz" zostawia relację niepowiązaną z ważnym linkiem (R1).
    Dane: `$hasAccount`, `$token`.
--}}
<div class="rounded-md border border-line bg-surface px-5 py-4">
    <h2 class="font-display text-lg font-semibold text-ink">
        {{ $hasAccount ? __('Sign in to see your reservation history') : __('Create an account to keep your reservation history') }}
    </h2>
    <p class="mt-1 text-muted">
        {{ $hasAccount
            ? __('You already have a Fisherya account with this address. Sign in to link this fishery with your account.')
            : __('With a Fisherya account you see all your reservations in one place. It takes a minute.') }}
    </p>
    <p class="mt-1 text-sm text-muted">{{ __('After linking, the fishery sees your name, email address and phone number from your Fisherya account — and nothing else.') }}</p>
    <form method="POST" action="{{ \App\Services\PortalRoutes::reservationUrl('confirm', $token) }}" class="mt-4 flex flex-wrap items-center gap-4">
        @csrf
        <button type="submit" name="action" value="account" class="rounded-md bg-b600 px-5 py-2.5 font-semibold text-white hover:bg-b700">
            {{ $hasAccount ? __('Sign in and link') : __('Create an account') }}
        </button>
        <a href="{{ \App\Services\PortalRoutes::homeUrl() }}" class="font-semibold text-b600 hover:underline">{{ __('Not now') }}</a>
    </form>
</div>
