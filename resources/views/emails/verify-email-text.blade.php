@extends('emails.text.layout')

@section('body')
{!! __('Thank you for registering! Click the button below to verify your email address.') !!}

{!! __('Verify e-mail address') !!}: {!! $url !!}

{!! __('If you did not create an account, no further action is required.') !!}
@endsection
