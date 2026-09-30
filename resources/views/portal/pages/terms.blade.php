{{-- Strona informacyjna — treść zastępcza do czasu ostatecznego tekstu (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Portal terms'))
@section('description', __('Portal terms') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', ['eyebrow' => __('Legal documents'), 'heading' => __('Portal terms')])
@endsection
