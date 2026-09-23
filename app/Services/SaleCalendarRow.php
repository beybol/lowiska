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
     */
    private function __construct(
        public Position $position,
        public array $cells,
        public bool $withdrawn,
    ) {}

    /**
     * @param  array<int, SaleCalendarCell>  $cells
     */
    public static function of(Position $position, array $cells): self
    {
        return new self($position, $cells, false);
    }

    public static function withdrawn(Position $position): self
    {
        return new self($position, [], true);
    }
}
