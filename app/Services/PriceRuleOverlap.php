<?php

namespace App\Services;

use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * Czy warunki dwóch reguł cenowych da się spełnić JEDNOCZEŚNIE.
 *
 * ⚠️ Klasa istnieje, żeby nie było drugiego literału tej reguły. Pytają o nią dwa miejsca
 * o różnym skutku: walidacja remisu (**błąd** zapisu) i ostrzeżenie o stawce bez warunku roli
 * z priorytetem wyższym niż stawka osoby towarzyszącej (**ostrzeżenie**). Różnią się wyłącznie
 * tym, co sprawdzają PONAD przecięciem — priorytet i szczegółowość.
 *
 * ⚠️ **Dni tygodnia i zakres dat sprawdza się RAZEM, nie oś po osi** — i to nie jest
 * optymalizacja (ADR-014). Stawka „30.04–02.05" i stawka „piątki" sprawdzane osobno przecinają
 * się **zawsze**: oś dni, bo pierwsza reguła jej nie ma, a oś dat, bo druga jej nie ma. Naprawdę
 * przecinają się tylko wtedy, gdy w tym zakresie wypada piątek. Ponieważ remis jest błędem
 * zapisu, wersja oś-po-osi **nie pozwoliłaby zapisać** poprawnego cennika.
 */
final class PriceRuleOverlap
{
    /**
     * Ile dni przecięcia wystarczy, żeby na pewno zawierało każdy dzień tygodnia.
     *
     * ⚠️ Skrót „wystarczy niepuste przecięcie dni" wolno zastosować wyłącznie przy przedziale
     * nieograniczonym albo obejmującym co najmniej tyle dni. Sam otwarty koniec jednej z reguł
     * **nie wystarcza**: „od 01.01.2026" w parze z „30.04–02.05" daje przecięcie zamknięte
     * i trzydniowe, więc trzeba je przejrzeć dobami.
     */
    private const DAYS_COVERING_EVERY_WEEKDAY = 7;

    public function canMatchSimultaneously(PriceRule $a, PriceRule $b): bool
    {
        // Osie NIEZALEŻNE od doby — każda musi się przecinać z osobna.
        // ⚠️ Oś pusta przecina się ze wszystkim.
        if (! $this->scalarAxesIntersect($a->anglers_count, $b->anglers_count)) {
            return false;
        }

        if (! $this->scalarAxesIntersect($a->participant_role?->value, $b->participant_role?->value)) {
            return false;
        }

        if (! $this->rangesIntersect(
            $a->effective_from?->toDateString(),
            $a->effective_to?->toDateString(),
            $b->effective_from?->toDateString(),
            $b->effective_to?->toDateString(),
        )) {
            return false;
        }

        return $this->intersectionHasCommonWeekday($a, $b);
    }

    private function scalarAxesIntersect(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null || (string) $a === (string) $b;
    }

    private function rangesIntersect(?string $aFrom, ?string $aTo, ?string $bFrom, ?string $bTo): bool
    {
        $from = $this->laterOf($aFrom, $bFrom);
        $to = $this->earlierOf($aTo, $bTo);

        return $from === null || $to === null || $from <= $to;
    }

    /**
     * Czy istnieje DOBA w przecięciu zakresów dat, której dzień rozpoczęcia należy do
     * przecięcia zbiorów dni tygodnia.
     */
    private function intersectionHasCommonWeekday(PriceRule $a, PriceRule $b): bool
    {
        $weekdays = $this->commonWeekdays($a, $b);

        if ($weekdays === []) {
            return false;
        }

        $from = $this->laterOf($a->first_day_on?->toDateString(), $b->first_day_on?->toDateString());
        $to = $this->earlierOf($a->last_day_on?->toDateString(), $b->last_day_on?->toDateString());

        if ($from !== null && $to !== null && $from > $to) {
            return false;
        }

        // Przecięcie otwarte z którejkolwiek strony zawiera każdy dzień tygodnia.
        if ($from === null || $to === null) {
            return true;
        }

        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        if ($start->diffInDays($end) >= self::DAYS_COVERING_EVERY_WEEKDAY - 1) {
            return true;
        }

        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            if (in_array((int) $date->isoWeekday(), $weekdays, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Przecięcie zbiorów dni tygodnia; oś pusta znaczy „wszystkie dni".
     *
     * @return array<int, int>
     */
    private function commonWeekdays(PriceRule $a, PriceRule $b): array
    {
        $all = range(1, 7);
        $aDays = $a->weekdayNumbers() ?: $all;
        $bDays = $b->weekdayNumbers() ?: $all;

        return array_values(array_intersect($aDays, $bDays));
    }

    private function laterOf(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a > $b ? $a : $b;
    }

    private function earlierOf(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a < $b ? $a : $b;
    }
}
