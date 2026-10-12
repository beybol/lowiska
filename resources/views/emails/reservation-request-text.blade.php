@extends('emails.text.layout')

@section('body')
{!! __('The fishery has made a reservation for you. Please confirm it with one click — no account is needed.') !!}

@include('emails.text.details', ['details' => $details])
@if ($refundLines !== [])

{!! __('Refund policy') !!}:
@foreach ($refundLines as $line)
- {!! $line !!}
@endforeach
@endif

{!! __('Confirm reservation') !!}: {!! $confirmUrl !!}

{!! __('Cancel / this is not me') !!}: {!! $manageUrl !!}

{!! __('After confirming, you can sign in to your Fisherya account or create one to keep your reservation history.') !!}
@endsection
