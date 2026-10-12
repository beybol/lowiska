<?php

namespace App\Enums;

/**
 * Rodzaj zmiany, który niesie nowa wersja dokumentu — wybierany przy publikacji (ADR-028 §2).
 *
 * Kolumna istnieje od zadania 049; skutki (powiadomienie, ekran ponownej akceptacji po zalogowaniu,
 * blokada zakupu) budują kolejne zadania. ⚠️ Zawarte umowy się nie zmieniają — rezerwacja ma zamrożone
 * warunki (ADR-026), więc rodzaj zmiany dotyczy wyłącznie przyszłych działań.
 */
enum DocumentChangeKind: string
{
    /** Poprawka redakcyjna — bez powiadomienia. */
    case Editorial = 'editorial';

    /** Zmiana do wiadomości — powiadomienie, bez ponownej akceptacji. */
    case Notice = 'notice';

    /** Zmiana wymagająca ponownej akceptacji. */
    case Reacceptance = 'reacceptance';

    public function label(): string
    {
        return match ($this) {
            self::Editorial => __('Editorial change — no notice'),
            self::Notice => __('Change to be announced — no new acceptance'),
            self::Reacceptance => __('Change requiring a new acceptance'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
