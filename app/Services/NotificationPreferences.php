<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Czy wiadomość techniczna może pójść tym kanałem (zadanie 049, ADR-028 §3a) — jedno miejsce odczytu.
 *
 * ⚠️ E-mail zawsze tak: kanał techniczny musi działać, więc nie da się go wyłączyć. SMS i push — wyłącznie
 * po włączeniu przez użytkownika (domyślnie wyłączone). Telefonu tu nie ma: rozmowę techniczną prowadzi
 * człowiek, system jej nie wysyła. Wiadomość techniczna nie zawiera treści marketingowej.
 */
final class NotificationPreferences
{
    public function allows(User $user, ConsentChannel $channel): bool
    {
        if ($channel === ConsentChannel::Email) {
            return true;
        }

        if ($channel === ConsentChannel::Phone) {
            return false;
        }

        $preference = NotificationPreference::query()->where('user_id', $user->getKey())->first();

        return match ($channel) {
            ConsentChannel::Sms => (bool) $preference?->sms,
            ConsentChannel::Push => (bool) $preference?->push,
        };
    }
}
