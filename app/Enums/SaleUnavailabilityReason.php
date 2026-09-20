<?php

namespace App\Enums;

/**
 * Powód, dla którego doby nie da się sprzedać.
 *
 * Istnieje, bo odmowa ma nieść powód, a nie samo „nie" — wędkarz musi wiedzieć,
 * czy trafił przed sezon, za sezon, czy w łowisko bez skonfigurowanej sprzedaży
 * (zadanie 015, kryteria akceptacji).
 */
enum SaleUnavailabilityReason: string
{
    /** Łowisko nie ma zdefiniowanych godzin doby — nie wiadomo, co sprzedaje. */
    case FishingDayNotConfigured = 'fishing_day_not_configured';

    /** Łowisko nie ma ani jednego okresu sprzedaży. */
    case NoSalePeriodDefined = 'no_sale_period_defined';

    /** Doba zaczyna się przed oknem każdego okresu sprzedaży. */
    case StartsBeforeSalePeriod = 'starts_before_sale_period';

    /** Doba kończy się po oknie każdego okresu sprzedaży. */
    case EndsAfterSalePeriod = 'ends_after_sale_period';

    /** Doba wypada między okresami sprzedaży albo przez granicę dwóch z nich. */
    case OutsideSalePeriod = 'outside_sale_period';

    public function label(): string
    {
        return match ($this) {
            self::FishingDayNotConfigured => __('The fishery has no fishing day defined'),
            self::NoSalePeriodDefined => __('The fishery has no sale period defined'),
            self::StartsBeforeSalePeriod => __('The fishing day starts before the sale period'),
            self::EndsAfterSalePeriod => __('The fishing day ends after the sale period'),
            self::OutsideSalePeriod => __('The fishing day is outside every sale period'),
        };
    }
}
