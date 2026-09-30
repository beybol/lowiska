<?php

namespace App\Rules;

use App\Services\PortalSlugs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ręczna zmiana sluga łowiska nie może zająć adresu aplikacji (zadanie 031, ADR-021 opcja B).
 *
 * Slug łowiska jest krótkim adresem wprost pod domeną, więc `admin`, `login` albo pierwszy segment
 * dowolnej zarejestrowanej trasy zasłoniłby łowisko albo trasę. Przy nadawaniu automatycznym ta sama
 * reguła daje sufiks (`PortalSlugs::forFishery()`); przy ręcznej zmianie — błąd, bo admin wybiera
 * adres świadomie i sufiks by go zaskoczył.
 */
class FisherySlugIsNotReserved implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (PortalSlugs::collidesWithApplication((string) $value)) {
            $fail(__('This address is used by the application. Choose another one (at least :min characters).', [
                'min' => PortalSlugs::MIN_FISHERY_LENGTH,
            ]));
        }
    }
}
