{{-- Ramka informacyjna (`info`) albo ostrzegawcza (`warning`). --}}
@props(['type' => 'info'])
@php
    $t = \App\Mail\MailTheme::class;
    $warning = $type === 'warning';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
    <tr>
        <td style="padding:12px 14px; border-radius:8px; font-size:14px; line-height:1.5; background-color:{{ $warning ? $t::WARN_BG : $t::INFO_BG }}; border-left:4px solid {{ $warning ? $t::WARN : $t::INFO_BORDER }}; color:{{ $t::INK2 }};">
            {{ $slot }}
        </td>
    </tr>
</table>
