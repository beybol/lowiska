<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Doba wędkarska jako PRZEDZIAŁ DWÓCH MOMENTÓW, nie data kalendarzowa (ADR-010).
 *
 * Przechodzi przez północ, a przy zmianie czasu trwa 23 albo 25 godzin — i mimo to
 * jest jedną dobą. Identyfikuje ją dzień rozpoczęcia (`startsOn`).
 *
 * ⚠️ Obie reguły granic mieszkają tutaj i nigdzie indziej. Są celowo asymetryczne:
 * okres sprzedaży DOPUSZCZA, więc wymaga zawierania (`isContainedIn`); ograniczenie
 * WYŁĄCZA, więc wystarczy dotknięcie (`overlaps`). Nie zastępuj jednej drugą —
 * pomyłka w którąkolwiek stronę kończy się sprzedażą doby, której nie wolno sprzedać.
 */
final readonly class FishingDay
{
    public function __construct(
        public CarbonImmutable $startsOn,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /**
     * Reguła dla OKRESÓW SPRZEDAŻY: doba musi mieścić się w oknie w całości.
     * Samo zetknięcie nie wystarcza — musi się zaczynać i kończyć wewnątrz.
     */
    public function isContainedIn(CarbonInterface $windowStart, CarbonInterface $windowEnd): bool
    {
        return $this->startsAt >= $windowStart && $this->endsAt <= $windowEnd;
    }

    /**
     * Reguła dla OGRANICZEŃ I BLOKAD: wystarczy, że jakakolwiek część doby wypada
     * w oknie. Dzięki temu nie da się sprzedać doby wchodzącej w zakaz choćby na
     * godzinę. Zadanie 015 tej reguły nie używa — definiuje ją dla zadania 016.
     */
    public function overlaps(CarbonInterface $windowStart, CarbonInterface $windowEnd): bool
    {
        return $this->startsAt < $windowEnd && $this->endsAt > $windowStart;
    }

    /**
     * Rzeczywista długość doby w godzinach — 23, 24 albo 25.
     *
     * ⚠️ Liczona na znacznikach czasu, nie przez różnicę dat lokalnych: przy zmianie
     * czasu różnica dat lokalnych daje 24 niezależnie od tego, co wydarzyło się
     * na zegarze (ADR-010, „momenty, nie daty lokalne").
     */
    public function lengthInHours(): float
    {
        return ($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 3600;
    }
}
