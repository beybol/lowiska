<?php

namespace App\Rules;

use App\Enums\PositionAttributeType;
use App\Models\PositionAttribute;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Wymagane cechy usługi to wyłącznie istniejące cechy typu FLAGA (zadanie 020).
 *
 * ⚠️ Tylko flagę da się zawiesić ograniczeniem (016), a „ma cechę" dla liczby albo wyboru z listy
 * nie ma jednego znaczenia. Zawężenie opcji w formularzu nie jest walidacją — wartość pola
 * wielokrotnego wyboru przychodzi od klienta (`autoryzacja.md` §4). Zapis i tak przechodzi przez
 * bramkę `AdditionalServiceSync::syncRequiredAttributes()`; ta reguła daje operatorowi komunikat.
 */
final class PositionAttributesAreFlags implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $given = array_values(array_unique(array_filter(is_array($value) ? $value : [$value], fn ($id): bool => filled($id))));
        $ids = RecordsBelongToFishery::normalize($given);

        $flags = $ids === []
            ? 0
            : PositionAttribute::query()
                ->whereKey($ids)
                ->where('type', PositionAttributeType::Flag->value)
                ->count();

        if (count($given) !== count($ids) || $flags !== count($ids)) {
            $fail(__('Only yes/no attributes can be required by a service.'));
        }
    }
}
