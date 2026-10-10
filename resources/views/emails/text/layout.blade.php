{{--
    Wspólny układ wariantu TEKSTOWEGO wiadomości (zadanie 040). Tekst, nie HTML — wartości wypisuje się
    przez nawiasy bez escapowania, bo encje HTML w czystym tekście wyglądałyby jak „&amp;".
--}}
{!! $subjectLine !!}
{!! str_repeat('=', min(60, mb_strlen($subjectLine))) !!}
@if ($fishery)
{!! __('Message from the fishery') !!}: {!! $fishery->name !!}
@endif

@yield('body')

--
@if ($fishery && ($fishery->phone || $fishery->email))
{!! __('Contact the fishery') !!}: {!! collect([$fishery->phone, $fishery->email])->filter()->implode(' · ') !!}
@endif
{!! $reason !!}
{!! \App\Mail\MailTheme::BRAND !!} · {!! config('app.url') !!}
