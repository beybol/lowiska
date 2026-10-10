<?php

namespace App\Services;

use App\Enums\ServiceBillingUnit;
use App\Models\AdditionalService;

/**
 * Jedna usługa stanowiska w wycenie pobytu (zadanie 039, aktualizacja ADR-015).
 *
 * Niesie to, czego potrzebuje formularz rezerwacji i zamrożone warunki: jednostkę, liczbę
 * egzemplarzy, cenę jednostkową i kwotę, limit oraz liczbę egzemplarzy, która została w terminie.
 * Kwoty w groszach.
 */
final readonly class StayServiceLine
{
    /**
     * @param  array<int, PositionServiceProblem>  $problems  puste = usługa dostępna w dobach pobytu
     */
    public function __construct(
        public AdditionalService $service,
        public bool $isRequired,
        public array $problems,
        public ServiceBillingUnit $unit,
        public int $quantity,
        public int $nights,
        public int $unitPriceInCents,
        public ?int $availableCount,
        public ?int $remaining,
    ) {}

    public function isAvailable(): bool
    {
        return $this->problems === [];
    }

    public function isChosen(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * Rachunek jednostki (`cennik.md` §7): za dobę — cena × doby × liczba; za pobyt — cena × liczba.
     */
    public function amountInCents(): int
    {
        return match ($this->unit) {
            ServiceBillingUnit::PerNight => $this->unitPriceInCents * $this->nights * $this->quantity,
            ServiceBillingUnit::PerStay => $this->unitPriceInCents * $this->quantity,
        };
    }

    public function exceedsStock(): bool
    {
        return $this->remaining !== null && $this->quantity > $this->remaining;
    }
}
