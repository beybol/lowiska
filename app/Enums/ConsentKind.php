<?php

namespace App\Enums;

/**
 * Rodzaj zapisu w rejestrze zgód (`consent_events`, ADR-028 „Trzy rodzaje zapisów").
 *
 * ⚠️ Preferencje powiadomień technicznych NIE są tu zapisywane — to nie zgoda, tylko stan bieżący
 * (`notification_preferences`, ADR-028 §3a).
 */
enum ConsentKind: string
{
    /** Akceptacja warunków umowy (regulamin) — bez wycofania. */
    case Acceptance = 'acceptance';

    /** Potwierdzenie zapoznania się (polityka prywatności) — obowiązek informacyjny, nie zgoda. */
    case Acknowledgement = 'acknowledgement';

    /** Zgoda marketingowa RODO — nadawca + kanał, z możliwością wycofania. */
    case Marketing = 'marketing';

    /** Rodzaj zapisu dla dokumentu danego rodzaju. */
    public static function forDocument(DocumentType $type): self
    {
        return $type === DocumentType::PrivacyPolicy ? self::Acknowledgement : self::Acceptance;
    }
}
