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
}
