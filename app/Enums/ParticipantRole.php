<?php

namespace App\Enums;

/**
 * Rola uczestnika pobytu — oś warunku cenowego i parametr zapytania o cenę.
 *
 * ⚠️ Uczestnik jest PARAMETREM ZAPYTANIA, nie rekordem w schemacie. Wycena pyta „ilu
 * łowiących, ile osób towarzyszących", a nie „kto konkretnie" — model rezerwacji
 * i uczestników powstanie dopiero razem z transakcją (G1, poza tą iteracją).
 *
 * ⚠️ **Osoba towarzysząca nie jest wyjątkiem w kodzie.** Wycenia się ją regułą `rate`
 * z warunkiem `participant_role = companion` i kwotą `0.00` (O15). Nie dorabiaj dla niej
 * gałęzi — niezmiennik i jego pułapka są w `docs/conventions/cennik.md`.
 */
enum ParticipantRole: string
{
    /** Osoba łowiąca — domyślny uczestnik pobytu. */
    case Angler = 'angler';

    /** Osoba towarzysząca, niełowiąca. */
    case Companion = 'companion';

    public function label(): string
    {
        return match ($this) {
            self::Angler => __('Angler'),
            self::Companion => __('Companion'),
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
