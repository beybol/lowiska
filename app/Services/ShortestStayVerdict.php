<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;
use Carbon\CarbonImmutable;

/**
 * Najkrótszy kupowalny pobyt rozpoczynający się wskazaną dobą — druga odpowiedź warstwy
 * oferty, obok „czy ten pobyt wolno kupić i ile kosztuje" (ADR-015).
 *
 * ⚠️ Istnieje dla kalendarza podglądowego (019), którego domyślnym widokiem są **„ceny od"**.
 * Bez tej odpowiedzi 019 miałby dwa wyjścia, oba złe: próbować kolejnych długości (przy siatce
 * stanowiska × doby to dziesiątki tysięcy wywołań) albo **odtworzyć u siebie reguły z 017** —
 * czyli złamać ADR-013 i zrobić z widoku drugie źródło prawdy.
 *
 * ⚠️ **Doba w środku pakietu nie ma odpowiedzi „ile", tylko „gdzie zacząć".** Wynik niesie wtedy
 * `startEarlierOn` — pierwszą dobę pakietu — żeby wołający mógł powiedzieć „zacznij w środę",
 * zamiast pokazywać samą odmowę.
 */
final readonly class ShortestStayVerdict
{
    private function __construct(
        public bool $available,
        public ?int $nights = null,
        public ?StayPriceBreakdown $breakdown = null,
        public ?SaleUnavailabilityReason $reason = null,
        public ?CarbonImmutable $startEarlierOn = null,
    ) {}

    public static function found(int $nights, StayPriceBreakdown $breakdown): self
    {
        return new self(true, $nights, $breakdown);
    }

    /** Pobytu nie da się zacząć tą dobą, bo leży ona w środku pakietu. */
    public static function startsEarlier(
        SaleUnavailabilityReason $reason,
        CarbonImmutable $bundleFirstDay,
    ): self {
        return new self(false, reason: $reason, startEarlierOn: $bundleFirstDay);
    }

    /** Żadna długość pobytu rozpoczynającego się tą dobą nie jest kupowalna. */
    public static function none(SaleUnavailabilityReason $reason): self
    {
        return new self(false, reason: $reason);
    }
}
