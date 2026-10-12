<?php

namespace App\Enums;

/**
 * Stan relacji łowisko ↔ wędkarz (`fishery_anglers`, ADR-024 pkt 1).
 *
 * Dane efektywne zależą od stanu (ADR-024 pkt 2): `Unlinked`/`Pending` — dane podane przez
 * operatora (`given_*`); `Linked` — konto wędkarza z nadpisaniem telefonu; `Revoked` — wyłącznie
 * zamrożony `contact_snapshot` rezerwacji. Przepływ powiązania: zadanie 043; cofanie dostępu: etap 4.
 */
enum AnglerLinkStatus: string
{
    case Unlinked = 'unlinked';

    case Pending = 'pending';

    case Linked = 'linked';

    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Unlinked => __('Not linked'),
            self::Pending => __('Link requested'),
            self::Linked => __('Linked'),
            self::Revoked => __('Access revoked'),
        };
    }

    /**
     * Stan wędkarza widoczny dla łowiska (zadanie 049, ADR-024 aktualizacja 2026-10-11 pkt 4) — odrębny od
     * statusu rezerwacji: „potwierdzona" nie oznacza już „powiązana z kontem". Oczekująca prośba to wciąż
     * dane od łowiska, także gdy wędkarz potwierdził rezerwację bez konta (R1).
     */
    public function accountLabel(): string
    {
        return match ($this) {
            self::Linked => __('Confirmed account'),
            self::Revoked => __('Access revoked'),
            default => __('Entered manually'),
        };
    }

    public function accountColor(): string
    {
        return $this === self::Linked ? 'success' : 'gray';
    }
}
