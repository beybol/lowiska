<?php

namespace App\Enums;

/**
 * Powód, dla którego doby ALBO POBYTU nie da się sprzedać.
 *
 * Istnieje, bo odmowa ma nieść powód, a nie samo „nie" — wędkarz musi wiedzieć,
 * czy trafił przed sezon, za sezon, czy w łowisko bez skonfigurowanej sprzedaży
 * (zadanie 015, kryteria akceptacji).
 *
 * ⚠️ Enum jest JEDEN dla całej sprzedaży (`docs/conventions/dostepnosc.md` §2).
 * Pierwsze siedem wartości dotyczy pojedynczej DOBY (`PositionAvailability`),
 * kolejne sześć — POBYTU, czyli ciągu dób kupowanego razem (`StaySellability`,
 * zadanie 017, ADR-013), a ostatnia — braku ceny, który też jest odmową sprzedaży
 * (zadanie 018, ADR-014/ADR-015). Nowy warunek dokłada wartość tutaj, nie zakłada
 * własnego słownika komunikatów.
 *
 * ⚠️ Każda nowa wartość wymaga DWÓCH zmian: gałęzi w wyczerpującym `match`
 * w `label()` — brak którejkolwiek to `UnhandledMatchError` dopiero w chwili
 * odmowy — oraz wpisu w `lang/pl.json`, bo komunikat widzi wędkarz.
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

    /** Stanowisko wycofane ze sprzedaży decyzją operatora — niezależnie od dat (zadanie 016). */
    case PositionWithdrawn = 'position_withdrawn';

    /** Doba przecina okno blokady sprzedaży na tym stanowisku (zadanie 016). */
    case SaleBlocked = 'sale_blocked';

    /** Pobyt jest krótszy niż `min_nights` łowiska (zadanie 017). */
    case StayTooShort = 'stay_too_short';

    /** Pobyt jest dłuższy niż `max_nights` łowiska (zadanie 017). */
    case StayTooLong = 'stay_too_long';

    /**
     * Pobyt przecina weekend sprzedawany w całości, nie obejmując go cały
     * (zadanie 017). Werdykt niesie PEŁNY zakres pakietu, który trzeba objąć.
     */
    case WeekendBroken = 'weekend_broken';

    /**
     * Pobyt przecina święto sprzedawane w całości, nie obejmując całego pakietu
     * (zadanie 017). ⚠️ Ten powód niesie także pakiet ZLANY ze weekendem, o ile
     * jest w nim choć jedna doba święta — rozstrzygnięcie 22 zadania 017.
     */
    case WholeTermBroken = 'whole_term_broken';

    /** Któraś doba pobytu leży dalej niż `sale_horizon_days` łowiska (zadanie 017). */
    case BeyondSaleHorizon = 'beyond_sale_horizon';

    /**
     * Pobyt jest krótszy niż `presale_min_nights` okresu, którego okno przedsprzedaży
     * jest właśnie otwarte (zadanie 017). Przedsprzedaż jest ofertą hurtową.
     */
    case BelowPresaleMinimum = 'below_presale_minimum';

    /**
     * Do doby w otwartym sezonie nie pasuje żadna stawka cennika (zadanie 018, ADR-014).
     *
     * ⚠️ Dziura w cenniku jest ODMOWĄ, nie ceną zerową. Powód nazywa **warstwa oferty**,
     * nie wycena — ta zgłasza tylko, że nie umie wycenić doby, i dzięki temu zostaje wolna
     * od słownika odmów sprzedaży (ADR-015).
     */
    case NoPriceDefined = 'no_price_defined';

    /**
     * Stawka na tę dobę istnieje, ale nie ma kwoty za osobę towarzyszącą (zadanie 018).
     *
     * ⚠️ **Osobny powód, nie `NoPriceDefined`** — te dwie odmowy prowadzą operatora
     * w przeciwne strony: tam trzeba DOPISAĆ stawkę, tu POPRAWIĆ jedno pole w stawce,
     * która już istnieje. Kalendarz (019) pokazuje powód wprost, więc zlanie ich w jedno
     * posłałoby go szukać nieistniejącej dziury w cenniku.
     */
    case NoCompanionPrice = 'no_companion_price';

    public function label(): string
    {
        return match ($this) {
            self::FishingDayNotConfigured => __('The fishery has no fishing day defined'),
            self::NoSalePeriodDefined => __('The fishery has no sale period defined'),
            self::StartsBeforeSalePeriod => __('The fishing day starts before the sale period'),
            self::EndsAfterSalePeriod => __('The fishing day ends after the sale period'),
            self::OutsideSalePeriod => __('The fishing day is outside every sale period'),
            self::PositionWithdrawn => __('The position is withdrawn from sale'),
            self::SaleBlocked => __('Sale at this position is blocked on that day'),
            self::StayTooShort => __('The stay is shorter than this fishery allows'),
            self::StayTooLong => __('The stay is longer than this fishery allows'),
            self::WeekendBroken => __('This weekend is sold whole — take all of its nights or none'),
            self::WholeTermBroken => __('This term is sold whole — take all of its nights or none'),
            self::BeyondSaleHorizon => __('That date is further ahead than this fishery sells'),
            self::BelowPresaleMinimum => __('The presale of this season requires a longer stay'),
            self::NoPriceDefined => __('This fishery has no price for that night'),
            self::NoCompanionPrice => __('The rate for that night has no price for a companion'),
        };
    }
}
