<?php

namespace App\Enums;

/**
 * Rodzaj dokumentu łowiska i szablonu — pole `documents.type` i `document_templates.type` (zadanie 021).
 *
 * ⚠️ Rodzaje działają IDENTYCZNIE — rodzaj jest polem dokumentu, a nie osobnym mechanizmem.
 * Regulamin platformy (TODO-1) dojdzie jako kolejny rodzaj bez przepisywania (ADR-017).
 *
 * ⚠️ `Other` to dokument łowiska o własnym tytule wersji („Zasady biwakowania") — jak pozostałe
 * rodzaje ma NAJWYŻEJ JEDNĄ wersję obowiązującą (zadanie 034, R9). Kilka dokumentów jednego
 * rodzaju naraz wymagałoby tożsamości dokumentu niezależnej od rodzaju, czyli odwrócenia ADR-017.
 */
enum DocumentType: string
{
    case Terms = 'terms';

    case PrivacyPolicy = 'privacy_policy';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Terms => __('Terms and conditions'),
            self::PrivacyPolicy => __('Privacy policy'),
            self::Other => __('Other'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
