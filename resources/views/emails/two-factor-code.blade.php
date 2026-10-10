<x-mail.layout :title="__('Login authorization code')" :fishery="$fishery" :reason="$reason">
    <x-mail.text>{{ __('Your authorization code: :code.', ['code' => $code]) }}</x-mail.text>

    <x-mail.button :href="$url">{{ __('Enter code') }}</x-mail.button>

    <x-mail.text muted>{{ __('This code will expire in 10 minutes.') }}</x-mail.text>
</x-mail.layout>
