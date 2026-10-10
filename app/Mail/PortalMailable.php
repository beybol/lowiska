<?php

namespace App\Mail;

use App\Models\Fishery;
use App\Services\PortalRoutes;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Wspólna podstawa każdej wiadomości aplikacji (zadanie 040).
 *
 * ⚠️ **Nowa wiadomość = klasa dziedzicząca stąd + dwa widoki na komponentach `x-mail.*`** (HTML)
 * i `emails.partials.text-*` (tekst). Klasa daje: temat z prefiksem marki (i łowiska, gdy wiadomość
 * jest od łowiska), wariant tekstowy w każdej wiadomości (multipart), nagłówek kategorii oraz język
 * odbiorcy — jeden z `PortalRoutes::LOCALES`. Style żyją wyłącznie w komponentach i `MailTheme`.
 *
 * Przykład w `docs/conventions/integracje.md` §2.
 */
abstract class PortalMailable extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Fishery|null  $fishery  łowisko, w którego imieniu idzie wiadomość (nadawca merytoryczny)
     * @param  string|null  $mailLocale  język odbiorcy; spoza listy portalu → bieżący język aplikacji
     */
    public function __construct(
        public readonly ?Fishery $fishery = null,
        ?string $mailLocale = null,
    ) {
        $this->locale(in_array($mailLocale, PortalRoutes::LOCALES, true) ? $mailLocale : app()->getLocale());
    }

    /** Temat bez prefiksu — prefiks dokłada `envelope()`. */
    abstract protected function subjectLine(): string;

    /** Widok HTML na komponentach `x-mail.*`. */
    abstract protected function htmlView(): string;

    /** Widok tekstowy — każda wiadomość ma wariant tekstowy. */
    abstract protected function textView(): string;

    /** Krótki klucz wiadomości — nagłówek `X-Fisherya-Mail` i podgląd deweloperski. */
    abstract public static function key(): string;

    /**
     * Dane widoków poza wspólnymi (`fishery`, `subjectLine`).
     *
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [];
    }

    /** Dlaczego odbiorca dostaje wiadomość — stopka layoutu i wariantu tekstowego. */
    protected function reason(): string
    {
        return __('You are receiving this message because of an action on your account on Fisherya.');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->prefixedSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: $this->htmlView(),
            text: $this->textView(),
            with: array_merge([
                'fishery' => $this->fishery,
                'subjectLine' => $this->subjectLine(),
                'reason' => $this->reason(),
            ], $this->viewData()),
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Fisherya-Mail' => static::key()]);
    }

    /** Wariant tekstowy wyrenderowany w języku odbiorcy — dla podglądu i testów renderu. */
    public function renderText(): string
    {
        return $this->withLocale($this->locale, function (): string {
            $content = $this->content();

            return view((string) $content->text, array_merge($this->buildViewData(), $content->with))->render();
        });
    }

    /**
     * Temat z prefiksem marki, a przy wiadomości od łowiska — także z jego nazwą.
     * Liczony w języku odbiorcy, bo tłumaczenie tematu zapada przed wysyłką.
     */
    public function prefixedSubject(): string
    {
        return $this->withLocale($this->locale, function (): string {
            $parts = [MailTheme::BRAND];

            if ($this->fishery !== null) {
                $parts[] = $this->fishery->name;
            }

            return implode(' · ', $parts).' — '.$this->subjectLine();
        });
    }
}
