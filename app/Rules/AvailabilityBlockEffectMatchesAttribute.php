<?php

namespace App\Rules;

use App\Enums\BlockEffect;
use App\Enums\PositionAttributeType;
use App\Models\PositionAttribute;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Skutek wpisu i wskazana cecha muszą do siebie pasować:
 * `attribute_suspended` WYMAGA cechy typu `flag`, `sale_blocked` nie może jej wskazywać.
 *
 * ⚠️ Zawiesić można wyłącznie cechę typu `flag` — zawiesza się to, co stanowisko MA
 * albo czego NIE MA. Liczba i wybór z listy opisują rzeczywistość, która nie znika
 * na dwa tygodnie; ich „zawieszenie" byłoby nadpisaniem, czyli innym pojęciem
 * (zadanie 016, „Rozstrzygnięcia").
 *
 * Reguła jest walidowana na polu cechy, a skutek dostaje w konstruktorze — formularz
 * i każda inna ścieżka zapisu wołają tę samą klasę.
 */
class AvailabilityBlockEffectMatchesAttribute implements ValidationRule
{
    public function __construct(private readonly ?BlockEffect $effect) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->effect === null) {
            return;
        }

        if ($this->effect === BlockEffect::SaleBlocked) {
            if (filled($value)) {
                $fail(__('A sale block must not point at an attribute.'));
            }

            return;
        }

        if (blank($value)) {
            $fail(__('An attribute suspension must point at an attribute.'));

            return;
        }

        $definition = PositionAttribute::find($value);

        if (! $definition instanceof PositionAttribute) {
            $fail(__('Unknown position attribute.'));

            return;
        }

        if ($definition->type !== PositionAttributeType::Flag) {
            $fail(__('Only a yes/no attribute can be suspended.'));
        }
    }
}
