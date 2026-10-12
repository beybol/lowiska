<x-mail.layout :title="$subjectLine" :fishery="$fishery" :reason="$reason" :preheader="__('The fishery has made a reservation for you — confirm it with one click.')">
    <x-mail.text>{{ __('The fishery has made a reservation for you. Please confirm it with one click — no account is needed.') }}</x-mail.text>

    <x-mail.details :rows="$details" :title="__('Reservation details')" />

    @if ($refundLines !== [])
        <x-mail.notice>
            <strong>{{ __('Refund policy') }}:</strong><br>
            @foreach ($refundLines as $line){{ $line }}<br>@endforeach
        </x-mail.notice>
    @endif

    <x-mail.button :href="$confirmUrl">{{ __('Confirm reservation') }}</x-mail.button>
    <x-mail.button :href="$manageUrl" variant="secondary">{{ __('Cancel / this is not me') }}</x-mail.button>

    <x-mail.text muted>{{ __('After confirming, you can sign in to your Fisherya account or create one to keep your reservation history.') }}</x-mail.text>
</x-mail.layout>
