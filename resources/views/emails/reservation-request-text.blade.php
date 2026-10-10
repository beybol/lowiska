@extends('emails.text.layout')

@section('body')
{!! __('The fishery has made a reservation for you. Please confirm it — you can also link it with your Fisherya account in the same step.') !!}

@include('emails.text.details', ['details' => $details])
@if ($refundLines !== [])

{!! __('Refund policy') !!}:
@foreach ($refundLines as $line)
- {!! $line !!}
@endforeach
@endif

{!! __('Confirm and link with my account') !!}: {!! $confirmUrl !!}

{!! __('Cancel / this is not me') !!}: {!! $manageUrl !!}

{!! __('After linking, the fishery sees your name, email address and phone number from your account. You can change them in your account at any time.') !!}
@endsection
