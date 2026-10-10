<?php

namespace App\Mail;

/**
 * Potwierdzenie adresu e-mail po rejestracji — treść sprzed zadania 040, na wspólnym layoucie.
 * Wysyła ją powiadomienie `VerifyEmail` (`AppServiceProvider::boot()`).
 */
class VerifyEmailMail extends PortalMailable
{
    public function __construct(public readonly string $url, ?string $mailLocale = null)
    {
        parent::__construct(null, $mailLocale);
    }

    public static function key(): string
    {
        return 'verify-email';
    }

    protected function subjectLine(): string
    {
        return __('Verify e-mail address');
    }

    protected function htmlView(): string
    {
        return 'emails.verify-email';
    }

    protected function textView(): string
    {
        return 'emails.verify-email-text';
    }

    protected function reason(): string
    {
        return __('You are receiving this message because this address was used to register on Fisherya.');
    }

    protected function viewData(): array
    {
        return ['url' => $this->url];
    }
}
