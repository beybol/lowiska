{{-- Favicon i ikony aplikacji — jeden zestaw dla portalu i widoków Breeze (KSIEGA-ZNAKU.md §10, zadanie 031).
     Panele Filamenta dostają ten sam `favicon.svg` przez `->favicon()` w providerach. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon-180.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
