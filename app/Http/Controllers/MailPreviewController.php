<?php

namespace App\Http\Controllers;

use App\Mail\MailPreviews;
use Illuminate\Http\Response;

/**
 * Podgląd wiadomości bez wysyłki (zadanie 040). Trasa istnieje tylko w `local`/`testing`; kontroler
 * sprawdza środowisko jeszcze raz — gdyby trasa trafiła kiedyś do innego środowiska, zwróci 404.
 */
class MailPreviewController extends Controller
{
    public function index(): Response
    {
        abort_unless(MailPreviews::allowed(), 404);

        $links = collect(array_keys(MailPreviews::all()))
            ->map(fn (string $key): string => sprintf(
                '<li>%1$s — <a href="%2$s">HTML</a> · <a href="%3$s">%4$s</a></li>',
                e($key),
                e(route('dev.mail-preview', ['mail' => $key])),
                e(route('dev.mail-preview', ['mail' => $key, 'format' => 'text'])),
                e(__('Text')),
            ))
            ->implode('');

        return response('<!DOCTYPE html><meta charset="utf-8"><title>Mail preview</title><ul>'.$links.'</ul>');
    }

    public function show(string $mail, string $format = 'html'): Response
    {
        abort_unless(MailPreviews::allowed(), 404);

        $mailable = MailPreviews::make($mail);
        abort_if($mailable === null, 404);

        if ($format === 'text') {
            return response($mailable->renderText(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        abort_unless($format === 'html', 404);

        return response($mailable->render());
    }
}
