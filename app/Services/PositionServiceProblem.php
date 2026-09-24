<?php

namespace App\Services;

use App\Enums\ServiceUnavailabilityReason;
use App\Models\AvailabilityBlock;
use App\Models\PositionAttribute;

/**
 * Jeden powód niedostępności usługi na stanowisku — wyjaśnialny (G11, zadanie 020).
 *
 * ⚠️ Zawieszenie niesie WPIS ograniczenia (powód i zakres dat), a nie samą cechę: bez dat
 * operator nie wie, czy przyczepa jest niedostępna dziś, czy od czerwca.
 */
final readonly class PositionServiceProblem
{
    private function __construct(
        public PositionAttribute $attribute,
        public ServiceUnavailabilityReason $reason,
        public ?AvailabilityBlock $suspension,
    ) {}

    public static function notSpecified(PositionAttribute $attribute): self
    {
        return new self($attribute, ServiceUnavailabilityReason::AttributeNotSpecified, null);
    }

    public static function absent(PositionAttribute $attribute): self
    {
        return new self($attribute, ServiceUnavailabilityReason::AttributeAbsent, null);
    }

    public static function suspended(PositionAttribute $attribute, AvailabilityBlock $block): self
    {
        return new self($attribute, ServiceUnavailabilityReason::AttributeSuspended, $block);
    }

    /** Tekst dla operatora: „wjazd pojazdem — ograniczenie od 01.06 do 30.06". */
    public function description(): string
    {
        return match ($this->reason) {
            ServiceUnavailabilityReason::AttributeNotSpecified => __(':attribute — not specified on this position', [
                'attribute' => $this->attribute->name,
            ]),
            ServiceUnavailabilityReason::AttributeAbsent => __(':attribute — this position does not have it', [
                'attribute' => $this->attribute->name,
            ]),
            ServiceUnavailabilityReason::AttributeSuspended => $this->suspensionDescription(),
        };
    }

    private function suspensionDescription(): string
    {
        $block = $this->suspension;
        $from = $block?->starts_on?->format('d.m');

        $text = $block?->ends_on === null
            ? __(':attribute — restriction from :from until further notice', [
                'attribute' => $this->attribute->name,
                'from' => $from,
            ])
            : __(':attribute — restriction from :from to :to', [
                'attribute' => $this->attribute->name,
                'from' => $from,
                'to' => $block->ends_on->format('d.m'),
            ]);

        return filled($block?->reason) ? $text.' ('.$block->reason.')' : $text;
    }
}
