<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\SaleUnavailabilityReason;
use Carbon\CarbonImmutable;

/**
 * Odpowiedź warstwy oferty: albo pobyt jest **sprzedawalny i wyceniony**, albo odmowa
 * z **jednym** powodem — niezależnie od tego, która warstwa niżej ją zgłosiła (ADR-015).
 *
 * ⚠️ Odmowa wskazuje, **czego dotyczy**, tak samo jak w 017: powód ze sprzedawalności niesie
 * dobę albo zakres pakietu (przepuszczony z `StaySellabilityVerdict`), a odmowa „brak ceny" —
 * dobę, rolę i obsadę, dla których zabrakło stawki. Sam powód bez wskazania jest bezużyteczny
 * przy pobycie wielodobowym i wieloosobowym: operator nie wie, którą regułę dopisać.
 */
final readonly class StayOfferVerdict
{
    private function __construct(
        public bool $available,
        public ?StayPriceBreakdown $breakdown = null,
        public ?SaleUnavailabilityReason $reason = null,
        public ?StaySellabilityVerdict $sellability = null,
        public ?CarbonImmutable $unpricedNight = null,
        public ?ParticipantRole $unpricedRole = null,
        public ?int $unpricedAnglersCount = null,
    ) {}

    public static function offered(StayPriceBreakdown $breakdown): self
    {
        return new self(true, $breakdown);
    }

    /** Odmowa ze sprzedawalności (017) — powód, doba albo zakres pakietu wracają bez zmian. */
    public static function notSellable(StaySellabilityVerdict $verdict): self
    {
        return new self(false, null, $verdict->reason, $verdict);
    }

    /** Odmowa z cennika — doba jest sprzedawalna, ale nie ma dla niej stawki. */
    public static function notPriced(StayPriceBreakdown $breakdown): self
    {
        return new self(
            available: false,
            reason: SaleUnavailabilityReason::NoPriceDefined,
            unpricedNight: $breakdown->failedNight,
            unpricedRole: $breakdown->failedRole,
            unpricedAnglersCount: $breakdown->failedAnglersCount,
        );
    }
}
