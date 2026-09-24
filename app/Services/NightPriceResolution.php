<?php

namespace App\Services;

use App\Enums\PricingFailure;
use App\Models\PriceRule;

/**
 * Wynik rozstrzygnięcia cennika dla JEDNEJ doby i jednej obsady.
 *
 * Albo zwycięska stawka wraz z pasującymi dopłatami, albo typowana informacja, że wyceny
 * nie da się podać — nigdy wyjątek (ADR-014).
 *
 * ⚠️ **Nie zależy od roli uczestnika.** Stawka zna wyłącznie daty, a dopłata mnoży się przez
 * skład dopiero w wycenie — rola przestała być osią warunku (ADR-014, „Aktualizacja").
 *
 * ⚠️ **Niesie KOMPLET kandydatów, nie samego zwycięzcę** — to diagnostyka dla kalendarza
 * podglądowego (019), który ma uwidocznić nachodzenie stawek. Resolver i tak materializuje
 * ten zbiór, żeby wybrać najtańszego; wcześniej po prostu wyrzucał przegranych. Diagnostyka
 * nie zmienia werdyktu i nie wchodzi do `StayPriceBreakdown`: rozbicie opisuje, za co wędkarz
 * płaci, a komplet kandydatów opisuje STAN KONFIGURACJI i jest osobnym pytaniem.
 */
final readonly class NightPriceResolution
{
    /**
     * @param  array<int, PriceRule>  $candidates  wszystkie pasujące stawki, w kolejności rozstrzygania
     * @param  array<int, PriceRule>  $surcharges
     */
    private function __construct(
        public ?PriceRule $rate,
        public array $candidates,
        public array $surcharges,
        public ?PricingFailure $failure,
    ) {}

    /**
     * @param  array<int, PriceRule>  $candidates
     * @param  array<int, PriceRule>  $surcharges
     */
    public static function resolved(PriceRule $rate, array $candidates, array $surcharges): self
    {
        return new self($rate, $candidates, $surcharges, null);
    }

    public static function failed(PricingFailure $failure): self
    {
        return new self(null, [], [], $failure);
    }

    public function isResolved(): bool
    {
        return $this->failure === null;
    }

    /** Kwota doby dla jednej osoby ŁOWIĄCEJ, bez dopłat, w groszach. */
    public function anglerAmountInCents(): int
    {
        return $this->rate?->amountInCents() ?? 0;
    }

    /**
     * Kwota doby dla jednej osoby TOWARZYSZĄCEJ, bez dopłat, w groszach — albo `null`,
     * gdy zwycięska stawka jej nie ma.
     *
     * ⚠️ `null` to BRAK CENY, nie zero. Rozstrzyga o tym wycena, bo tylko ona wie,
     * czy w zapytaniu w ogóle jest osoba towarzysząca.
     */
    public function companionAmountInCents(): ?int
    {
        return $this->rate?->companionAmountInCents();
    }
}
