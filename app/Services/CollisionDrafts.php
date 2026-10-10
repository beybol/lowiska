<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Zmiana konfiguracji czekająca na decyzje operatora — w SESJI, nie w bazie (zadanie 044).
 *
 * ⚠️ Zmiana nie zapisuje się przed ekranem decyzji: zapis zmiany i decyzje to jedna transakcja
 * (ADR-027 pkt 3). Sesja należy do zalogowanego operatora, więc szkic nie wycieka do innych kont;
 * ekran decyzji i tak sprawdza łowisko szkicu wobec rekordu strony.
 */
final class CollisionDrafts
{
    private const PREFIX = 'collision-drafts.';

    public static function store(ConfigurationChange $change): string
    {
        $token = Str::random(32);
        session()->put(self::PREFIX.$token, $change->toArray());

        return $token;
    }

    public static function get(string $token): ?ConfigurationChange
    {
        $data = session()->get(self::PREFIX.$token);

        return is_array($data) ? ConfigurationChange::fromArray($data) : null;
    }

    public static function forget(string $token): void
    {
        session()->forget(self::PREFIX.$token);
    }
}
