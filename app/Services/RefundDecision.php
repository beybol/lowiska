<?php

namespace App\Services;

use App\Enums\RefundOutcome;

/**
 * Odpowiedź `RefundPolicy` na pytanie o zwrot przy odwołaniu pobytu (zadanie 021).
 */
final readonly class RefundDecision
{
    private function __construct(
        public RefundOutcome $outcome,
        public ?int $percent,
        public ?int $daysBefore,
    ) {}

    public static function refund(int $percent, int $daysBefore): self
    {
        return new self(RefundOutcome::Refund, $percent, $daysBefore);
    }

    public static function policyNotSet(int $daysBefore): self
    {
        return new self(RefundOutcome::PolicyNotSet, null, $daysBefore);
    }

    public static function stayStarted(): self
    {
        return new self(RefundOutcome::StayStarted, null, null);
    }
}
