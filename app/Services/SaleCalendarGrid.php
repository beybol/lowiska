<?php

namespace App\Services;

use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * Gotowa siatka kalendarza podglądowego wraz z diagnostyką cennika (zadanie 019).
 *
 * ⚠️ **Diagnostyka jest OBOK siatki, nie w komórkach** — i to nie jest szczegół układu.
 * Kandydaci na stawkę zależą wyłącznie od daty (stawka po uproszczeniu 018 nie zna ani
 * stanowiska, ani składu uczestników), więc pobiera się ich **raz na okno**, a nie raz na
 * komórkę. Przy Klasztornym to różnica między 31 a 806 wywołaniami.
 */
final readonly class SaleCalendarGrid
{
    /**
     * @param  array<int, CarbonImmutable>  $days
     * @param  array<int, SaleCalendarRow>  $rows
     * @param  array<string, array<int, PriceRule>>  $rateCandidates  klucz: data doby
     * @param  array<int, PriceRule>  $deadRates
     */
    public function __construct(
        public array $days,
        public array $rows,
        public array $rateCandidates = [],
        public array $deadRates = [],
    ) {}

    /**
     * Ile stawek NACHODZI na siebie w tej dobie.
     *
     * ⚠️ **Zwraca 0 także wtedy, gdy pasuje dokładnie jedna stawka** — i to nie jest pomyłka
     * w nazwie, tylko kształt danych: `SaleCalendar` zapisuje kandydatów wyłącznie przy
     * nachodzeniu, bo tylko ono jest informacją dla operatora. Nie czytaj tej metody jako
     * „ile stawek obowiązuje"; na to odpowiada `PriceRuleResolver`.
     *
     * ⚠️ **Licznik pokazuje się przy KAŻDYM nachodzeniu, także przy identycznych kwotach.**
     * Dwie stawki na tę samą dobę są pomyłką zawsze — a dwie identyczne to najczystszy
     * przypadek bałaganu, który przy liczniku „tylko gdy kwoty się różnią" byłby jedynym
     * całkowicie niewidocznym.
     */
    public function overlappingRates(CarbonImmutable $night): int
    {
        return count($this->rateCandidates[$night->toDateString()] ?? []);
    }

    /**
     * Zwycięzca i przegrani tej doby — treść podpowiedzi „czemu widzę 70, skoro wpisałem 90".
     *
     * @return array<int, PriceRule>
     */
    public function ratesFor(CarbonImmutable $night): array
    {
        return $this->rateCandidates[$night->toDateString()] ?? [];
    }

    /**
     * ⚠️ **Oznaczenie martwej stawki jest JEDNO NA REGULE, nie na dobie.** Stawka w całości
     * przesłonięta tańszą jest martwym wpisem — operator myśli, że coś ustawił. Rozciągnięcie
     * tego na każdą dobę jej okresu zamieniłoby informację w szum.
     */
    public function isDeadRate(PriceRule $rule): bool
    {
        foreach ($this->deadRates as $dead) {
            if ((int) $dead->id === (int) $rule->id) {
                return true;
            }
        }

        return false;
    }
}
