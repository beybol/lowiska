{{--
    „Pliki cookie" — treść zastępcza z listą ciasteczek, które portal NAPRAWDĘ ustawia (zadanie 031,
    Rozstrzygnięcie 2: bez banera zgód, bo są wyłącznie techniczne). Nowe ciasteczko → wpis tutaj.
    Ostateczny tekst: TODO-1 (prawnik).
--}}
@extends('portal.layout')

@section('title', __('Cookies'))
@section('description', __('Which cookies the portal uses.'))

@section('content')
    @php
        $cookies = [
            [config('session.cookie'), __('keeps your visit together (session)')],
            ['XSRF-TOKEN', __('protects forms against forgery')],
            [\App\Services\PortalLocale::COOKIE, __('remembers the language you chose')],
        ];
        $rows = '';
        foreach ($cookies as [$name, $purpose]) {
            $rows .= '<li><code class="rounded-sm bg-line2 px-1.5 py-0.5 text-[13px]">'.e($name).'</code> — '.e($purpose).'</li>';
        }
    @endphp
    @include('portal.partials.page', [
        'eyebrow' => __('Legal documents'),
        'heading' => __('Cookies'),
        'body' => '<div class="space-y-4">'
            .'<p>'.e(__('The portal uses only technical cookies, needed for it to work. It does not use advertising or tracking cookies, and visit statistics are collected without cookies.')).'</p>'
            .'<ul class="list-disc space-y-2 pl-5">'.$rows.'</ul>'
            .'<p class="text-[13px] text-muted">'.e(__('The full text of this page is being prepared.')).'</p>'
            .'</div>',
    ])
@endsection
