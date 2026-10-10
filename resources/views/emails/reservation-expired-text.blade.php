@extends('emails.text.layout')

@section('body')
{!! __('The reservation was not confirmed in time, so it has expired and the position is free again.') !!}

@include('emails.text.details', ['details' => $details])

{!! __('If you still want to come, contact the fishery to book again — the details are below.') !!}
@endsection
