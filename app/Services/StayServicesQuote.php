<?php

namespace App\Services;

/**
 * Wycena usług dodatkowych pobytu — wszystkie usługi stanowiska z liczbą wybranych egzemplarzy
 * (zadanie 039). Usługi niewybrane są na liście z liczbą 0, żeby formularz mógł je zaproponować.
 */
final readonly class StayServicesQuote
{
    /**
     * @param  array<int, StayServiceLine>  $lines
     * @param  array<int, int>  $unknownServiceIds  żądane usługi, których stanowisko nie oferuje
     */
    public function __construct(
        public array $lines,
        public array $unknownServiceIds = [],
    ) {}

    /**
     * @return array<int, StayServiceLine>
     */
    public function chosen(): array
    {
        return array_values(array_filter($this->lines, fn (StayServiceLine $line): bool => $line->isChosen()));
    }

    public function totalInCents(): int
    {
        $total = 0;

        foreach ($this->chosen() as $line) {
            $total += $line->amountInCents();
        }

        return $total;
    }
}
