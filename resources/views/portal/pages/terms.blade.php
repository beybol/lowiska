{{-- Regulamin portalu — wersja obowiązująca dokumentu Fisherya (zadanie 049) albo treść zastępcza (zadanie 031). --}}
@extends('portal.layout')

@section('title', __('Portal terms'))
@section('description', __('Portal terms') . ' · Fisherya')

@section('content')
    @include('portal.partials.page', [
        'eyebrow' => __('Legal documents'),
        'heading' => $document['title'] ?? __('Portal terms'),
        'body' => $document['body'] ?? null,
    ])
@endsection
