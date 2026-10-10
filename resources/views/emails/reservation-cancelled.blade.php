<x-mail.layout :title="$subjectLine" :fishery="$fishery" :reason="$reason">
    <x-mail.text>{{ __('The fishery has cancelled your reservation.') }}@if ($cancelReason) {{ $cancelReason }}.@endif</x-mail.text>

    <x-mail.fishery-message :message="$fisheryMessage" :from="$fishery?->name" />

    <x-mail.details :rows="$details" :title="__('Reservation details')" />

    <x-mail.notice>{{ __('You will not be charged for this reservation.') }}</x-mail.notice>

    <x-mail.text muted>{{ __('If you have questions, contact the fishery directly — the details are below.') }}</x-mail.text>
</x-mail.layout>
