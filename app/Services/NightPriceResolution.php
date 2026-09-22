<?php

namespace App\Services;

use App\Enums\PricingFailure;
use App\Models\PriceRule;

/**
 * Wynik rozstrzygnięcia cennika dla JEDNEJ doby, jednej roli i jednej obsady.
 *
 * Albo zwycięska stawka wraz z pasującymi dopłatami, albo typowana informacja, że wyceny
 * nie da się podać — nigdy wyjątek (ADR-014).
 */
final readonly class NightPriceResolution
{
    /**
     * @param  array<int, PriceRule>  $surcharges
     */
    private function __construct(
        public ?PriceRule $rate,
        public array $surcharges,
        public ?PricingFailure $failure,
    ) {}

    /**
     * @param  array<int, PriceRule>  $surcharges
     */
    public static function resolved(PriceRule $rate, array $surcharges): self
    {
        return new self($rate, $surcharges, null);
    }

    public static function failed(PricingFailure $failure): self
    {
        return new self(null, [], $failure);
    }

    public function isResolved(): bool
    {
        return $this->failure === null;
    }

    /** Kwota doby dla jednej osoby tej roli, w groszach. */
    public function amountInCents(): int
    {
        if ($this->rate === null) {
            return 0;
        }

        $total = $this->rate->amountInCents();

        foreach ($this->surcharges as $surcharge) {
            $total += $surcharge->amountInCents();
        }

        return $total;
    }
}
