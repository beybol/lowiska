<?php

namespace App\Services;

use App\Enums\ServiceBillingUnit;
use App\Models\AdditionalService;

/**
 * Jedna usługa w wycenie pobytu (zadanie 039, aktualizacja ADR-015; niedostępność — zadanie 047).
 *
 * Niesie to, czego potrzebuje formularz rezerwacji i zamrożone warunki: jednostkę, liczbę
 * egzemplarzy, cenę jednostkową i kwotę, limit oraz liczbę egzemplarzy, która została w terminie.
 * Kwoty w groszach.
 *
 * Trzy rodzaje niedostępności, o różnej naturze (zadanie 047, B6):
 * - `offered = false` — usługa łowiska nieoferowana na tym stanowisku (zasięg, brak przypięcia);
 * - `problems` — brak albo zawieszenie wymaganej cechy;
 * - `exceedsStock()` — w dobach pobytu zostało za mało egzemplarzy.
 * Dwa pierwsze operator może przekroczyć wyjątkiem z powodem; trzeciego nie przekracza nikt —
 * to podwójna sprzedaż tego samego przedmiotu, jak zajęty slot (ADR-025 pkt 7).
 */
final readonly class StayServiceLine
{
    /**
     * @param  array<int, PositionServiceProblem>  $problems  puste = cechy w porządku
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
        public bool $offered = true,
    ) {}

    /** Usługa jest na stanowisku i wymagane cechy są — limit egzemplarzy sprawdza `exceedsStock()`. */
    public function isAvailable(): bool
    {
        return $this->offered && $this->problems === [];
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

    /**
     * Przyczyny niedostępności dla operatora — ostrzeżenie w formularzu przed zapisem (047, B6).
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if (! $this->offered) {
            $warnings[] = __('not offered on this position');
        }

        foreach ($this->problems as $problem) {
            $warnings[] = $problem->description();
        }

        if ($this->exceedsStock()) {
            $warnings[] = __(':remaining of :total left on those nights', [
                'remaining' => (int) $this->remaining,
                'total' => (int) $this->availableCount,
            ]);
        }

        return $warnings;
    }
}
