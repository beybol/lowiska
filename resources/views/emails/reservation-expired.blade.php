<x-mail.layout :title="$subjectLine" :fishery="$fishery" :reason="$reason">
    <x-mail.text>{{ __('The reservation was not confirmed in time, so it has expired and the position is free again.') }}</x-mail.text>

    <x-mail.details :rows="$details" :title="__('Reservation details')" />

    <x-mail.notice>{{ __('If you still want to come, contact the fishery to book again — the details are below.') }}</x-mail.notice>
</x-mail.layout>
