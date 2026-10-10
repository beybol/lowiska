@extends('emails.text.layout')

@section('body')
{!! __('Your authorization code: :code.', ['code' => $code]) !!}

{!! __('Enter code') !!}: {!! $url !!}

{!! __('This code will expire in 10 minutes.') !!}
@endsection
