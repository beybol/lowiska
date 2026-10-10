<x-mail.layout :title="__('Verify e-mail address')" :fishery="$fishery" :reason="$reason">
    <x-mail.text>{{ __('Thank you for registering! Click the button below to verify your email address.') }}</x-mail.text>

    <x-mail.button :href="$url">{{ __('Verify e-mail address') }}</x-mail.button>

    <x-mail.text muted>{{ __('If you did not create an account, no further action is required.') }}</x-mail.text>
</x-mail.layout>
