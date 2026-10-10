{{-- Przycisk maila: główny (wypełniony) albo drugorzędny (obrys). Tabela, bo Outlook ignoruje padding linku. --}}
@props(['href', 'variant' => 'primary'])
@php
    $t = \App\Mail\MailTheme::class;
    $primary = $variant === 'primary';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px;">
    <tr>
        <td style="border-radius:8px; background-color:{{ $primary ? $t::PRIMARY : $t::CARD }}; border:2px solid {{ $t::PRIMARY }};">
            <a href="{{ $href }}" target="_blank" rel="noopener" style="display:inline-block; padding:11px 22px; font-size:15px; font-weight:bold; text-decoration:none; color:{{ $primary ? $t::PRIMARY_TEXT : $t::PRIMARY }}; font-family:{{ $t::FONT }};">{{ $slot }}</a>
        </td>
    </tr>
</table>
