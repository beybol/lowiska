{{-- Strona informacyjna — treść zastępcza do czasu ostatecznego tekstu (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Privacy policy'))
@section('description', __('Privacy policy') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', ['eyebrow' => __('Legal documents'), 'heading' => __('Privacy policy')])
@endsection
