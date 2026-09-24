<?php

namespace App\Rules;

use App\Models\Fishery;
use App\Services\FisheryDocuments;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Data wejścia w życie wersji dokumentu — najwcześniej JUTRO w strefie łowiska (zadanie 021).
 *
 * ⚠️ Dziś albo wcześniej to błąd przy tworzeniu i przy edycji: wersja obowiązuje od północy dnia
 * wejścia w życie i od tej chwili jest nienaruszalna, więc data dzisiejsza nie zostawiałaby
 * operatorowi ani chwili na poprawkę.
 */
final class DocumentEffectiveDateIsAhead implements ValidationRule
{
    public function __construct(private readonly Fishery $fishery) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        try {
            $date = CarbonImmutable::parse(substr((string) $value, 0, 10), $this->fishery->timezone ?: 'Europe/Warsaw')->startOfDay();
        } catch (Throwable) {
            $fail(__('Enter a valid date.'));

            return;
        }

        if ($date <= FisheryDocuments::today($this->fishery)) {
            $fail(__('A version can take effect tomorrow at the earliest.'));
        }
    }
}
