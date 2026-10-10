<?php

namespace App\Services;

use App\Enums\BookingProblem;
use App\Enums\SaleUnavailabilityReason;
use App\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * Wynik operacji `ReservationBooking`.
 *
 * ⚠️ Konflikt terminu jest ODMOWĄ z powodem (`SaleUnavailabilityReason::Occupied`), nie błędem
 * (ADR-025 pkt 4) — wołający pokazuje komunikat, a nie stronę błędu. Odmowa wskazuje dobę, gdy
 * dotyczy doby, i usługę, gdy dotyczy usługi.
 */
final readonly class BookingOutcome
{
    private function __construct(
        public bool $succeeded,
        public ?Reservation $reservation = null,
        public ?SaleUnavailabilityReason $reason = null,
        public ?BookingProblem $problem = null,
        public ?CarbonImmutable $night = null,
        public ?int $serviceId = null,
    ) {}

    public static function succeeded(Reservation $reservation): self
    {
        return new self(true, $reservation);
    }

    public static function refused(SaleUnavailabilityReason $reason, ?CarbonImmutable $night = null): self
    {
        return new self(false, reason: $reason, night: $night);
    }

    public static function problem(
        BookingProblem $problem,
        ?SaleUnavailabilityReason $reason = null,
        ?CarbonImmutable $night = null,
        ?int $serviceId = null,
    ): self {
        return new self(false, problem: $problem, reason: $reason, night: $night, serviceId: $serviceId);
    }

    /** Komunikat dla operatora — problem składu ma pierwszeństwo przed powodem odmowy sprzedaży. */
    public function message(): ?string
    {
        if ($this->problem !== null) {
            return $this->problem->label().($this->reason !== null ? ' — '.$this->reason->label() : '');
        }

        return $this->reason?->label();
    }
}
