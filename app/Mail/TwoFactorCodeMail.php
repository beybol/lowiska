<?php

namespace App\Mail;

/**
 * Kod drugiego składnika logowania — wysyła go `SendTwoFactorCode`, na wspólnym layoucie (zadanie 040).
 *
 * ⚠️ Kod przychodzi w konstruktorze, a nie z modelu użytkownika w widoku: wiadomość nie może
 * serializować `User` z żywym kodem (`two_factor_code` jest ukryty przy serializacji modelu).
 */
class TwoFactorCodeMail extends PortalMailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $url,
        ?string $mailLocale = null,
    ) {
        parent::__construct(null, $mailLocale);
    }

    public static function key(): string
    {
        return 'two-factor-code';
    }

    protected function subjectLine(): string
    {
        return __('Login authorization code');
    }

    protected function htmlView(): string
    {
        return 'emails.two-factor-code';
    }

    protected function textView(): string
    {
        return 'emails.two-factor-code-text';
    }

    protected function reason(): string
    {
        return __('You are receiving this message because someone is signing in to your Fisherya account.');
    }

    protected function viewData(): array
    {
        return ['code' => $this->code, 'url' => $this->url];
    }
}
