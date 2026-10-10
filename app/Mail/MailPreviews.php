<?php

namespace App\Mail;

use Closure;

/**
 * Rejestr podglądu wiadomości z danymi przykładowymi (zadanie 040) — trasa `/dev/mail-preview`.
 *
 * ⚠️ **Nowa wiadomość dopisuje tu swój wpis** — inaczej nie da się obejrzeć jej wyglądu bez wysyłki,
 * a test podglądu przestaje ją obejmować. Wpis buduje wiadomość bez zapisu w bazie (modele `make()`
 * albo obiekty w pamięci), bo podgląd nie może zostawiać śladów w danych.
 *
 * Podgląd działa wyłącznie w środowiskach `local` i `testing` — trasa nie jest rejestrowana nigdzie
 * indziej, a kontroler sprawdza środowisko jeszcze raz.
 */
final class MailPreviews
{
    /** @var array<int, string> */
    public const ENVIRONMENTS = ['local', 'testing'];

    public static function allowed(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /**
     * @return array<string, Closure(): PortalMailable>
     */
    public static function all(): array
    {
        return [
            VerifyEmailMail::key() => fn (): PortalMailable => new VerifyEmailMail(url('/verify-email/1/preview')),
            TwoFactorCodeMail::key() => fn (): PortalMailable => new TwoFactorCodeMail('123456', url('/verify')),
        ];
    }

    public static function make(string $key): ?PortalMailable
    {
        $factory = self::all()[$key] ?? null;

        return $factory === null ? null : $factory();
    }
}
