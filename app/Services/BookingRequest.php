<?php

namespace App\Services;

use App\Enums\ReservationSource;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Żądanie zapisu rezerwacji — wejście JEDYNEJ ścieżki zapisu (`ReservationBooking`, ADR-025 pkt 4).
 *
 * `startsOn` to dzień rozpoczęcia pierwszej doby w strefie łowiska; `nights` — liczba dób pobytu.
 * `services`: id usługi → liczba egzemplarzy (usługi obowiązkowe dochodzą same).
 *
 * ⚠️ Wyjątki (`exceptionReason`, cena uzgodniona) przyjmuje wyłącznie kanał operatora i admina
 * (`ReservationSource::allowsExceptions()`); kanał wędkarza nigdy nie omija blokady (ADR-027 pkt 7).
 */
final readonly class BookingRequest
{
    /**
     * @param  array<int, int>  $services
     */
    public function __construct(
        public Position $position,
        public string $startsOn,
        public int $nights,
        public AnglerDetails|FisheryAngler $angler,
        public ReservationSource $source,
        public ?User $actor = null,
        public int $anglers = 1,
        public int $companions = 0,
        public array $services = [],
        public ?int $agreedTotalCents = null,
        public ?string $agreedPriceReason = null,
        public ?string $exceptionReason = null,
        // Powód dodania usługi niedostępnej (nieoferowanej, bez cechy) — wyjątek operatora (047, B6).
        public ?string $serviceExceptionReason = null,
        public ?string $internalNote = null,
        public ?CarbonImmutable $confirmBy = null,
    ) {}
}
