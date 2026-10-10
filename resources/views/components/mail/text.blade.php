{{-- Akapit treści maila. `muted` — drobny, przygaszony tekst (np. „jeśli to nie Ty, zignoruj"). --}}
@props(['muted' => false])
@php
    $t = \App\Mail\MailTheme::class;
@endphp
<p style="margin:0 0 16px; font-size:{{ $muted ? '13px' : '15px' }}; line-height:1.6; color:{{ $muted ? $t::MUTED : $t::INK2 }};">{{ $slot }}</p>
