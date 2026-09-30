{{-- Nagłówek wiersza stanowiska (§3.1): etykieta, grupy, pojemność, cechy filtrowalne i znaczniki. --}}
<b class="text-[13px]">{{ __('Pos. :label', ['label' => $header['label']]) }}</b>
@php($sub = array_filter([$header['groups'], $header['capacity'], ...$header['features']]))
@if ($sub !== [])
    <span class="block text-[11px] text-muted">{{ implode(' · ', $sub) }}</span>
@endif
@if ($header['restrictions'] !== [] || $header['services'] !== [] || $header['blocked'])
    <span class="mt-1 flex flex-wrap gap-1">
        @foreach ($header['restrictions'] as $restriction)
            <span class="rounded-sm bg-a100 px-1.5 py-px text-[10.5px] font-semibold text-a700">{{ $restriction }}</span>
        @endforeach
        @foreach ($header['services'] as $service)
            <span class="rounded-sm bg-line2 px-1.5 py-px text-[10.5px] font-semibold text-ink2">{{ $service }}</span>
        @endforeach
        @if ($header['blocked'])
            <span class="rounded-sm bg-a100 px-1.5 py-px text-[10.5px] font-semibold text-a700">{{ __('blocked on some nights') }}</span>
        @endif
    </span>
@endif
