{{--
    Layout wiadomości (zadanie 040): tabele, style inline, szerokość 600 px, bez Flexboxa i arkuszy.
    Kolory wyłącznie z App\Mail\MailTheme. Nagłówek z marką, opcjonalnie nazwa łowiska (nadawca
    merytoryczny), treść w slocie, stopka z wyjaśnieniem, dlaczego odbiorca dostaje wiadomość.
--}}
@props(['title', 'fishery' => null, 'reason' => null, 'preheader' => null])
@php
    $t = \App\Mail\MailTheme::class;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ $t::PAGE }}; font-family:{{ $t::FONT }}; color:{{ $t::INK }};">
@if ($preheader)
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $t::PAGE }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="{{ $t::WIDTH }}" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:{{ $t::WIDTH }}px; background-color:{{ $t::CARD }}; border:1px solid {{ $t::LINE }}; border-radius:12px;">
                <tr>
                    <td style="background-color:{{ $t::HEADER }}; padding:18px 24px; border-radius:12px 12px 0 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-right:10px; vertical-align:middle;">
                                    <img src="{{ $t::logoUrl() }}" width="32" height="32" alt="" style="display:block; border:0; border-radius:6px;">
                                </td>
                                <td style="vertical-align:middle; color:{{ $t::HEADER_TEXT }}; font-size:18px; font-weight:bold; letter-spacing:.02em;">
                                    {{ $t::BRAND }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @if ($fishery)
                    <tr>
                        <td style="padding:14px 24px 0; font-size:13px; color:{{ $t::MUTED }};">
                            {{ __('Message from the fishery') }}: <strong style="color:{{ $t::INK2 }};">{{ $fishery->name }}</strong>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:20px 24px 8px;">
                        <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; color:{{ $t::INK }};">{{ $title }}</h1>
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px 22px; border-top:1px solid {{ $t::LINE }}; font-size:12px; line-height:1.5; color:{{ $t::MUTED }};">
                        @isset($footer)
                            {{ $footer }}
                        @endisset
                        @if ($fishery && ($fishery->phone || $fishery->email))
                            <p style="margin:0 0 8px;">
                                {{ __('Contact the fishery') }}:
                                {{ collect([$fishery->phone, $fishery->email])->filter()->implode(' · ') }}
                            </p>
                        @endif
                        @if ($reason)
                            <p style="margin:0 0 8px;">{{ $reason }}</p>
                        @endif
                        <p style="margin:0;">{{ $t::BRAND }} · <a href="{{ config('app.url') }}" style="color:{{ $t::PRIMARY }};">{{ preg_replace('#^https?://#', '', (string) config('app.url')) }}</a></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
