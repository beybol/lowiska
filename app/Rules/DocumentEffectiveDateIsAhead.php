<?php

namespace App\Rules;

use App\Models\Fishery;
use App\Services\FisheryDocuments;
use App\Services\PlatformDocuments;
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
    /**
     * @param  Fishery|null  $fishery  `null` = dokument Fisherya (zadanie 049) — strefa domyślna portalu
     */
    public function __construct(private readonly ?Fishery $fishery) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $timezone = $this->fishery?->timezoneName() ?? Fishery::DEFAULT_TIMEZONE;

        try {
            $date = CarbonImmutable::parse(substr((string) $value, 0, 10), $timezone)->startOfDay();
        } catch (Throwable) {
            $fail(__('Enter a valid date.'));

            return;
        }

        $today = $this->fishery instanceof Fishery ? FisheryDocuments::today($this->fishery) : PlatformDocuments::today();

        if ($date <= $today) {
            $fail(__('A version can take effect tomorrow at the earliest.'));
        }
    }
}
