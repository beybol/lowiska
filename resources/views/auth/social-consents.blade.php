{{-- Zgody przed założeniem konta przez dostawcę logowania (zadanie 049) — konto powstaje dopiero po ich zaznaczeniu. --}}
<x-guest-layout>
    <form method="POST" action="{{ route('social.consents.submit') }}">
        @csrf

        <h1 class="text-lg font-semibold text-gray-900">{{ __('One more step') }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ __('Before we create your account (:email), accept the Fisherya documents.', ['email' => $email]) }}</p>

        @include('auth.partials.consents', ['documents' => $documents])

        <div class="mt-6 flex items-center justify-end">
            <x-primary-button>{{ __('Create an account') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
