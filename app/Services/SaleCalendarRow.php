<?php

namespace App\Services;

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
    ) {}

    /**
     * @param  array<int, SaleCalendarCell>  $cells
     * @param  array<int, PositionServiceStatus>  $services
     */
    public static function of(Position $position, array $cells, array $services = []): self
    {
        return new self($position, $cells, false, $services);
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
