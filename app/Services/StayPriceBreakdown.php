<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PricingFailure;
use Carbon\CarbonImmutable;

/**
 * Wynik wyceny pobytu: **rozbicie**, nie jedna liczba.
 *
 * ⚠️ Gdyby wycena zwracała samą sumę, kalendarz podglądowy (019) nie miałby czego pokazać,
 * a operator nie zrozumiałby, skąd wzięło się 110 zł. Rozbicie trafia do 019 i do przyszłego
 * koszyka **przez warstwę oferty** (ADR-015).
 *
 * ⚠️ **To nie jest snapshot G1.** Snapshot składa się z ceny z rozbiciem, polityki zwrotu,
 * wersji regulaminu, parametrów stanowiska i uczestników; dwa z tych składników powstają dopiero
 * w 021, a transakcji i uczestników nie ma w schemacie wcale. 018 wnosi wyłącznie **strukturę**,
 * którą przyszły snapshot utrwali.
 *
 * ⚠️ Niepowodzenie wyceny wraca TUTAJ, a nie wyjątkiem — i niesie **dobę, rolę i obsadę**,
 * dla których zabrakło stawki. Sam powód bez wskazania jest bezużyteczny przy pobycie
 * wielodobowym i wieloosobowym: operator nie wie, którą regułę dopisać.
 */
final readonly class StayPriceBreakdown
{
    /**
     * @param  array<int, StayNightPrice>  $nights
     */
    private function __construct(
        public array $nights,
        public ?PricingFailure $failure = null,
        public ?CarbonImmutable $failedNight = null,
        public ?ParticipantRole $failedRole = null,
        public ?int $failedAnglersCount = null,
    ) {}

    /**
     * @param  array<int, StayNightPrice>  $nights
     */
    public static function priced(array $nights): self
    {
        return new self($nights);
    }

    public static function failed(
        PricingFailure $failure,
        CarbonImmutable $night,
        ParticipantRole $role,
        int $anglersCount,
    ): self {
        return new self([], $failure, $night, $role, $anglersCount);
    }

    public function isPriced(): bool
    {
        return $this->failure === null;
    }

    /** Suma pobytu po obniżkach, w groszach. */
    public function totalInCents(): int
    {
        $total = 0;

        foreach ($this->nights as $night) {
            $total += $night->totalInCents();
        }

        return $total;
    }

    /** Suma pobytu przed obniżkami, w groszach. */
    public function subtotalInCents(): int
    {
        $total = 0;

        foreach ($this->nights as $night) {
            $total += $night->subtotalInCents();
        }

        return $total;
    }

    public function discountInCents(): int
    {
        return $this->subtotalInCents() - $this->totalInCents();
    }

    /**
     * Wszystkie pozycje rozbicia, spłaszczone — wygodne dla testów i dla 019.
     *
     * @return array<int, StayPriceItem>
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->nights as $night) {
            foreach ($night->items as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
