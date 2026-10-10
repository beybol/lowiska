<x-mail.layout :title="$subjectLine" :fishery="$fishery" :reason="$reason" :preheader="__('The fishery has made a reservation for you — confirm it with one click.')">
    <x-mail.text>{{ __('The fishery has made a reservation for you. Please confirm it — you can also link it with your Fisherya account in the same step.') }}</x-mail.text>

    <x-mail.details :rows="$details" :title="__('Reservation details')" />

    @if ($refundLines !== [])
        <x-mail.notice>
            <strong>{{ __('Refund policy') }}:</strong><br>
            @foreach ($refundLines as $line){{ $line }}<br>@endforeach
        </x-mail.notice>
    @endif

    <x-mail.button :href="$confirmUrl">{{ __('Confirm and link with my account') }}</x-mail.button>
    <x-mail.button :href="$manageUrl" variant="secondary">{{ __('Cancel / this is not me') }}</x-mail.button>

    <x-mail.text muted>{{ __('After linking, the fishery sees your name, email address and phone number from your account. You can change them in your account at any time.') }}</x-mail.text>
</x-mail.layout>
