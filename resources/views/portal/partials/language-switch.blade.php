{{-- Przełącznik PL · EN — prowadzi na ODPOWIEDNIK bieżącej strony, nie na stronę główną (ADR-021). --}}
<span class="font-semibold">
    @foreach ($alternates as $code => $url)
        @if (! $loop->first)<span aria-hidden="true"> · </span>@endif
        @if ($code === app()->getLocale())
            <span aria-current="true">{{ strtoupper($code) }}</span>
        @else
            <a href="{{ $url }}" hreflang="{{ $code }}" lang="{{ $code }}" class="{{ $linkClass ?? 'text-faint hover:text-b700' }}">{{ strtoupper($code) }}</a>
        @endif
    @endforeach
</span>
