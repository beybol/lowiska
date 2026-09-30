{{-- Strona informacyjna — treść zastępcza do czasu ostatecznego tekstu (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Report illegal content'))
@section('description', __('Report illegal content') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', ['eyebrow' => __('Legal documents'), 'heading' => __('Report illegal content')])
@endsection
