{{-- Komunikat łowiska do wędkarza (np. powód anulacji) — tekst operatora, escapowany, z zachowaniem akapitów. --}}
@props(['message', 'from' => null])
@php
    $t = \App\Mail\MailTheme::class;
@endphp
@if (filled($message))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
        <tr>
            <td style="padding:12px 14px; border-left:4px solid {{ $t::ACCENT }}; background-color:{{ $t::PAGE }}; font-size:14px; line-height:1.6; color:{{ $t::INK2 }};">
                @if ($from)
                    <div style="font-size:12px; font-weight:bold; color:{{ $t::MUTED }}; margin-bottom:4px;">{{ __('Message from :fishery', ['fishery' => $from]) }}</div>
                @endif
                {!! nl2br(e($message)) !!}
            </td>
        </tr>
    </table>
@endif
