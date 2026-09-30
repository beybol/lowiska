<?php

namespace App\Enums;

/**
 * Brak w danych łowiska sprawdzany przed publikacją w portalu (zadanie 030, portal-v3 §6).
 *
 * ⚠️ **Blokuje wyłącznie brak województwa** — bez niego strona łowiska nie ma adresu
 * kanonicznego. Każdy inny brak to ostrzeżenie: za błąd konfiguracji odpowiada Fisherya (D4),
 * a operator porządkuje obiekt w dowolnej kolejności.
 */
enum PublicationIssue: string
{
    case StateMissing = 'state_missing';

    case FishingDayMissing = 'fishing_day_missing';

    case SalePeriodMissing = 'sale_period_missing';

    case NoPositions = 'no_positions';

    case NoPositionsForSale = 'no_positions_for_sale';

    case PricingGap = 'pricing_gap';

    case PhoneMissing = 'phone_missing';

    case DescriptionMissing = 'description_missing';

    case PhotoMissing = 'photo_missing';

    case MapMissing = 'map_missing';

    case TermsMissing = 'terms_missing';

    public function blocksPublication(): bool
    {
        return $this === self::StateMissing;
    }

    public function label(): string
    {
        return match ($this) {
            self::StateMissing => __('The fishery has no state, so its page would have no address.'),
            self::FishingDayMissing => __('The fishing day hours are not set.'),
            self::SalePeriodMissing => __('There is no current or upcoming sale period.'),
            self::NoPositions => __('The fishery has no positions.'),
            self::NoPositionsForSale => __('No position is for sale.'),
            self::PricingGap => __('The pricing has a gap — some nights have no rate.'),
            self::PhoneMissing => __('There is no contact phone number.'),
            self::DescriptionMissing => __('The fishery has no description.'),
            self::PhotoMissing => __('The gallery has no photo.'),
            self::MapMissing => __('The fishery map is missing.'),
            self::TermsMissing => __('There are no fishery terms and conditions in force.'),
        };
    }
}
