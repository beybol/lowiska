<?php

namespace App\Services;

use App\Enums\SaleUnavailabilityReason;
use Carbon\CarbonImmutable;

/**
 * Odpowiedź na pytanie „czy ten POBYT wolno kupić", zawsze z powodem odmowy.
 *
 * ⚠️ To nie jest `FishingDayAvailability` z dodatkowymi polami i nie zastępuje go.
 * Tamten typ odpowiada o JEDNEJ dobie i zostaje nietknięty; ten niesie dodatkowo
 * **wskazanie, czego odmowa dotyczy**, bo przy pobycie wielodobowym sam powód jest
 * bezużyteczny (ADR-013, zadanie 017 rozstrzygnięcie 18):
 *
 * - powód z poziomu doby, przepuszczony z `PositionAvailability`, niesie `$day` —
 *   „stanowisko wycofane" bez daty nic nie mówi przy pobycie ośmiodobowym;
 * - powód ze spoiwa niesie `$bundleFirstDay`/`$bundleLastDay`, czyli **pełny zakres
 *   pakietu**, który trzeba objąć — a nie sam zakres święta czy weekendu. Bez tego
 *   wędkarz nie wie, ile dobrać;
 * - powody dotyczące całego pobytu (za krótki, za długi, minimum przedsprzedaży)
 *   nie niosą ani jednego, ani drugiego — ich przedmiotem jest pobyt jako całość.
 */
final readonly class StaySellabilityVerdict
{
    private function __construct(
        public bool $sellable,
        public ?SaleUnavailabilityReason $reason = null,
        public ?FishingDay $day = null,
        public ?CarbonImmutable $bundleFirstDay = null,
        public ?CarbonImmutable $bundleLastDay = null,
    ) {}

    public static function sellable(): self
    {
        return new self(true);
    }

    /**
     * Odmowa dotycząca konkretnej doby pobytu — własna albo przepuszczona
     * z `PositionAvailability`.
     */
    public static function refusedDay(SaleUnavailabilityReason $reason, ?FishingDay $day): self
    {
        return new self(false, $reason, $day);
    }

    /**
     * Odmowa ze spoiwa: niesie pełny zakres pakietu, który pobyt musi objąć.
     * Granice to DNI ROZPOCZĘCIA pierwszej i ostatniej doby pakietu.
     */
    public static function refusedBundle(
        SaleUnavailabilityReason $reason,
        CarbonImmutable $bundleFirstDay,
        CarbonImmutable $bundleLastDay,
    ): self {
        return new self(false, $reason, null, $bundleFirstDay, $bundleLastDay);
    }

    /** Odmowa dotycząca pobytu jako całości — długość albo minimum przedsprzedaży. */
    public static function refusedStay(SaleUnavailabilityReason $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Liczba dób pakietu, który trzeba objąć — dla komunikatu „kup całą Majówkę
     * (4 doby)". `null`, gdy odmowa nie pochodzi ze spoiwa.
     */
    public function bundleNights(): ?int
    {
        if ($this->bundleFirstDay === null || $this->bundleLastDay === null) {
            return null;
        }

        return (int) $this->bundleFirstDay->diffInDays($this->bundleLastDay) + 1;
    }
}
