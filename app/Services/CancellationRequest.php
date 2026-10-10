<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Models\User;

/**
 * Żądanie anulacji rezerwacji (zadanie 039; modal z 041, kolizje z 044, rezygnacja z 043).
 *
 * `refundPercent` — zwrot inny niż wynikający z warunków umowy, wymaga `refundReason` (ADR-026,
 * aktualizacja 10.10); `null` = zwrot z warunków (przy anulacji przez łowisko zawsze pełny, G8).
 * `message` — komunikat łowiska do wędkarza, trafia do maila (ADR-027 pkt 4).
 * `needsContact` — wędkarza trzeba powiadomić inną drogą (brak adresu albo błąd wysyłki).
 */
final readonly class CancellationRequest
{
    public function __construct(
        public CancellationReason $reason,
        public ?User $actor = null,
        public ?string $message = null,
        public ?int $refundPercent = null,
        public ?string $refundReason = null,
        public bool $needsContact = false,
    ) {}
}
