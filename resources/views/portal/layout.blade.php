{{--
    Układ portalu wędkarza — zadanie 031 (portal-v3 §1.1, ADR-021).

    ⚠️ Wejścia portalu (`portal.css`, `portal.js`) i NIC więcej — bez stylów i skryptów Filamenta i Breeze (ADR-020).
    ⚠️ Umami wyłącznie tutaj i wyłącznie przy komplecie konfiguracji (w praktyce: produkcja).
    ⚠️ `noindex` poza produkcją — staging i środowiska lokalne nie trafiają do wyszukiwarek.

    Sekcje: `title`, `description`, `content`; `$noCanonical` wyłącza canonical i `hreflang` (404).
--}}
@php
    $alternates = \App\Services\PortalRoutes::alternates(request()->route());
    // Przełącznik języka niesie stan kalendarza (033); canonical i hreflang — bez parametrów.
    $switchUrls = \App\Services\PortalRoutes::switchUrls(request()->route(), request()->query());
    $locale = app()->getLocale();
    $umamiScript = config('services.umami.script_url');
    $umamiWebsite = config('services.umami.website_id');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Fisherya</title>
    <meta name="description" content="@yield('description')">
    @unless (app()->isProduction())
        <meta name="robots" content="noindex, nofollow">
    @endunless
    @unless ($noCanonical ?? false)
        <link rel="canonical" href="{{ $alternates[$locale] }}">
        @foreach ($alternates as $hreflang => $url)
            <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $url }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $alternates[\App\Services\PortalRoutes::DEFAULT_LOCALE] }}">
    @endunless
    @include('partials.favicons')
    <meta name="theme-color" content="#135A6B">
    @vite(['resources/css/portal.css', 'resources/js/portal.js'])
    @if (filled($umamiScript) && filled($umamiWebsite))
        <script defer src="{{ $umamiScript }}" data-website-id="{{ $umamiWebsite }}"></script>
    @endif
</head>
<body class="min-h-screen bg-white font-sans text-[15px] leading-relaxed text-ink antialiased">
    @include('portal.partials.header', ['alternates' => $switchUrls])

    <main>
        @yield('content')
    </main>

    @include('portal.partials.footer', ['alternates' => $switchUrls])
</body>
</html>
