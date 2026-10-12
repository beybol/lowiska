{{-- Polityka prywatności portalu — wersja obowiązująca dokumentu Fisherya (zadanie 049) albo treść zastępcza (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Privacy policy'))
@section('description', __('Privacy policy') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', [
        'eyebrow' => __('Legal documents'),
        'heading' => $document['title'] ?? __('Privacy policy'),
        'body' => $document['body'] ?? null,
    ])
@endsection
