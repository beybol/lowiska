<?php

namespace App\Rules;

use App\Enums\PositionAttributeType;
use App\Models\PositionAttribute;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Wartość cechy musi pasować do typu cechy ze słownika, a wybór z listy —
 * do opcji przypisanych DOKŁADNIE TEJ cesze.
 *
 * ⚠️ To jest jedyny dom tej reguły. Schemat jej nie wyraża (trzy kolumny wartości,
 * z których właściwa zależy od `type`), więc bez tego pliku każdy formularz i każda
 * akcja zapisu pilnowałyby jej po swojemu — a druga kopia warunku jest defektem,
 * nie zabezpieczeniem (ADR-011).
 *
 * ⚠️ Reguła sprawdza też przynależność opcji do cechy. Identyfikator opcji przychodzi
 * z pola `Select`, czyli od klienta: bez tego podmiana stanu przypisałaby stanowisku
 * opcję należącą do zupełnie innej cechy (`CLAUDE.md`: każde ID to dane od klienta).
 */
class PositionAttributeValueMatchesType implements ValidationRule
{
    /**
     * @param  mixed  $value  mapa `id cechy => wartość`; brak klucza i `null`
     *                        oznaczają „nikt się nie wypowiedział" i są dozwolone
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $attributes = PositionAttribute::query()
            ->whereIn('id', array_keys($value))
            ->with('options')
            ->get()
            ->keyBy('id');

        foreach ($value as $attributeId => $rawValue) {
            $definition = $attributes->get($attributeId);

            if (! $definition instanceof PositionAttribute) {
                $fail(__('Unknown position attribute.'));

                return;
            }

            // Brak wartości to trzeci stan („nikt się nie wypowiedział"), nie błąd.
            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            $failure = $this->failureFor($definition, $rawValue);

            if ($failure !== null) {
                $fail($failure);

                return;
            }
        }
    }

    private function failureFor(PositionAttribute $definition, mixed $rawValue): ?string
    {
        return match ($definition->type) {
            PositionAttributeType::Flag => is_bool($rawValue) || in_array($rawValue, ['0', '1', 0, 1], true)
                ? null
                : __('The value of a yes/no attribute must be a boolean.'),
            PositionAttributeType::Number => is_numeric($rawValue)
                ? null
                : __('The value of a numeric attribute must be a number.'),
            PositionAttributeType::Choice => $definition->options->contains('id', (int) $rawValue)
                ? null
                : __('The chosen option does not belong to this attribute.'),
        };
    }
}
