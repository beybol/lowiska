<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Wycena JEDNEJ doby pobytu: pozycje wszystkich ról plus obniżka przedsprzedażowa.
 *
 * ⚠️ **Obniżka liczy się PER DOBA i jest widoczna przy każdej dobie** — to był warunek wyboru
 * tego wariantu. Podstawą jest **suma pozycji tej doby** (wszystkie role razem), a zaokrąglenie
 * następuje **raz**, do pełnego grosza, połówki w górę. Liczenie osobno na każdą pozycję
 * doba × osoba mnożyłoby zdarzenia zaokrąglenia bez żadnego zysku.
 *
 * ⚠️ Znane i świadomie przyjęte ograniczenie: suma obniżek dób może różnić się o grosze od
 * procentu policzonego od całości pobytu. **Nie „naprawiaj" tego przeliczaniem na całość** —
 * obniżka ma być widoczna przy każdej dobie (zadanie 018, rozstrzygnięcie 3).
 */
final readonly class StayNightPrice
{
    /**
     * @param  array<int, StayPriceItem>  $items
     */
    public function __construct(
        public CarbonImmutable $night,
        public array $items,
        public int $discountInCents = 0,
        public ?string $discountPercent = null,
    ) {}

    /** Kwota doby przed obniżką — podstawa, z której obniżka jest liczona. */
    public function subtotalInCents(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->amountInCents();
        }

        return $total;
    }

    public function totalInCents(): int
    {
        return $this->subtotalInCents() - $this->discountInCents;
    }
}
