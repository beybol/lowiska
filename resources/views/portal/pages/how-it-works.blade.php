{{-- Strona informacyjna — treść zastępcza do czasu ostatecznego tekstu (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('How it works'))
@section('description', __('How it works') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', ['eyebrow' => __('For anglers'), 'heading' => __('How it works')])
@endsection
