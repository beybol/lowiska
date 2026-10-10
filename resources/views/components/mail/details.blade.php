{{-- Blok „szczegóły" — tabela etykieta–wartość. `rows`: etykieta => wartość; puste wartości się nie pokazują. --}}
@props(['rows' => [], 'title' => null])
@php
    $t = \App\Mail\MailTheme::class;
    $rows = array_filter($rows, fn ($value) => filled($value));
@endphp
@if ($rows !== [])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px; border:1px solid {{ $t::LINE }}; border-radius:8px;">
        @if ($title)
            <tr>
                <td colspan="2" style="padding:10px 14px; font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:.06em; color:{{ $t::MUTED }}; border-bottom:1px solid {{ $t::LINE }};">{{ $title }}</td>
            </tr>
        @endif
        @foreach ($rows as $label => $value)
            <tr>
                <td style="padding:8px 14px; font-size:14px; color:{{ $t::MUTED }}; width:40%; vertical-align:top;">{{ $label }}</td>
                <td style="padding:8px 14px; font-size:14px; color:{{ $t::INK }}; font-weight:600; vertical-align:top;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
@endif
