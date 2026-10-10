@extends('emails.text.layout')

@section('body')
{!! __('The fishery has cancelled your reservation.') !!}@if ($cancelReason) {!! $cancelReason !!}.@endif

@if (filled($fisheryMessage))
{!! __('Message from :fishery', ['fishery' => $fishery?->name]) !!}:
{!! $fisheryMessage !!}

@endif
@include('emails.text.details', ['details' => $details])

{!! __('You will not be charged for this reservation.') !!}

{!! __('If you have questions, contact the fishery directly — the details are below.') !!}
@endsection
