{{-- Deklarację XML dokleja `SeoController::sitemap()` — `?>` w Blade zamknąłby blok PHP. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@foreach ($entry['alternates'] as $hreflang => $url)
        <xhtml:link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $url }}"/>
@endforeach
    </url>
@endforeach
</urlset>
