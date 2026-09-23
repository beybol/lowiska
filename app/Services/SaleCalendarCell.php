<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;
use Carbon\CarbonImmutable;

/**
 * Jedna komórka siatki: doba na stanowisku (zadanie 019).
 *
 * ⚠️ **Trzy stany i nic poza nimi** — to są trzy postacie `ShortestStayVerdict` zmapowane
 * wprost. Kalendarz nie dokłada własnych stanów ani nie liczy niczego sam: gdyby zaczął,
 * stałby się drugim źródłem prawdy o sprzedawalności (ADR-013, ADR-015).
 */
final readonly class SaleCalendarCell
{
    private function __construct(
        public CarbonImmutable $night,
        public bool $sellable,
        public ?int $nights = null,
        public ?int $totalInCents = null,
        public ?SaleUnavailabilityReason $reason = null,
        public ?CarbonImmutable $startEarlierOn = null,
        public ?CarbonImmutable $bundleFirstDay = null,
        public ?CarbonImmutable $bundleLastDay = null,
        public ?int $blockedPositionsCount = null,
        public ?string $blockSelectionLabel = null,
    ) {}

    /**
     * ⚠️ **Liczba dób jest CZĘŚCIĄ treści, nie ozdobnikiem.** W widoku „ceny od" sąsiadują
     * komórki o różnej długości — 130,00 zł za jedną dobę obok 520,00 zł za czterodobowy
     * pakiet. Kwota bez liczby dób wprowadza w błąd.
     */
    public static function sellable(CarbonImmutable $night, int $nights, int $totalInCents): self
    {
        return new self($night, true, nights: $nights, totalInCents: $totalInCents);
    }

    public static function refused(
        CarbonImmutable $night,
        SaleUnavailabilityReason $reason,
        ?CarbonImmutable $bundleFirstDay = null,
        ?CarbonImmutable $bundleLastDay = null,
        ?int $blockedPositionsCount = null,
        ?string $blockSelectionLabel = null,
    ): self {
        return new self(
            night: $night,
            sellable: false,
            reason: $reason,
            bundleFirstDay: $bundleFirstDay,
            bundleLastDay: $bundleLastDay,
            blockedPositionsCount: $blockedPositionsCount,
            blockSelectionLabel: $blockSelectionLabel,
        );
    }

    /**
     * Doba leży w ŚRODKU pakietu — żaden pobyt zaczynający się nią nie obejmie go w całości.
     *
     * ⚠️ Komórka niesie samą **dobę startu**; długość pakietu idzie do podpowiedzi, bo komórka
     * ma kilkanaście znaków szerokości.
     */
    public static function startsEarlier(
        CarbonImmutable $night,
        SaleUnavailabilityReason $reason,
        CarbonImmutable $startEarlierOn,
    ): self {
        return new self($night, false, reason: $reason, startEarlierOn: $startEarlierOn);
    }

    public function startsEarlierElsewhere(): bool
    {
        return $this->startEarlierOn instanceof CarbonImmutable;
    }
}
