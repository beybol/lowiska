<?php

namespace App\Services;

use App\Models\AvailabilityBlock;
use App\Models\Position;

/**
 * Jeden wiersz siatki — stanowisko wraz z jego dobami (zadanie 019).
 *
 * ⚠️ **Stanowisko wycofane zajmuje wiersz JEDNYM komunikatem na całą szerokość**, a nie
 * trzydziestoma identycznymi komórkami. Przyczyna jest niezależna od dat, więc powtarzanie
 * jej trzydzieści razy niczego nie dodaje, a przykrywa wiersze, w których naprawdę coś się
 * dzieje.
 */
final readonly class SaleCalendarRow
{
    /**
     * @param  array<int, SaleCalendarCell>  $cells  puste, gdy stanowisko jest wycofane
     * @param  array<int, PositionServiceStatus>  $services  usługi stanowiska w pokazywanym oknie
     */
    private function __construct(
        public Position $position,
        public array $cells,
        public bool $withdrawn,
        public array $services,
        public array $suspensions = [],
    ) {}

    /**
     * @param  array<int, SaleCalendarCell>  $cells
     * @param  array<int, PositionServiceStatus>  $services
     */
    /**
     * @param  array<int, AvailabilityBlock>  $suspensions  ograniczenia zawieszające cechę stanowiska,
     *                                                      przecinające którąś dobę okna (033)
     */
    public static function of(Position $position, array $cells, array $services = [], array $suspensions = []): self
    {
        return new self($position, $cells, false, $services, $suspensions);
    }

    /**
     * @param  array<int, PositionServiceStatus>  $services
     */
    public static function withdrawn(Position $position, array $services = []): self
    {
        return new self($position, [], true, $services);
    }

    /** Ile usług stanowiska jest w pokazywanym oknie niedostępnych — kolor plakietki. */
    public function unavailableServices(): int
    {
        return count(array_filter(
            $this->services,
            static fn (PositionServiceStatus $status): bool => ! $status->isAvailable(),
        ));
    }
}
