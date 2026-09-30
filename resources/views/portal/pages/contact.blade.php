{{-- Strona informacyjna — treść zastępcza do czasu ostatecznego tekstu (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Contact'))
@section('description', __('Contact') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', ['heading' => __('Contact')])
@endsection
