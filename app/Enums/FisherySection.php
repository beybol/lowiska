<?php

namespace App\Enums;

/**
 * Sekcje danych łowiska — JEDNA lista dla formularza (edycja w obu panelach, ostatni krok kreatora)
 * i podglądu „Dane łowiska" (zadanie 038, R5/R6).
 *
 * ⚠️ **Kolejność przypadków = kolejność sekcji na ekranie** i odpowiada zakładce „Szczegóły" portalu,
 * żeby operator wiedział, gdzie trafi pole. Formularz i podgląd budują się pętlą po `cases()`
 * (`FisheryResource::layout()`), więc nowa sekcja albo zmiana kolejności tutaj zmienia oba widoki naraz.
 * `FisherySectionTest` czerwienieje, gdy któryś widok nie ma pól dla sekcji.
 */
enum FisherySection: string
{
    case Basic = 'basic';

    case Description = 'description';

    case Address = 'address';

    case Contact = 'contact';

    case Water = 'water';

    case AnglerRules = 'angler_rules';

    case Conveniences = 'conveniences';

    case Map = 'map';

    case Gallery = 'gallery';

    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::Basic => __('Basic data'),
            self::Description => __('Fishery description'),
            self::Address => __('Address and directions'),
            self::Contact => __('Contact and links'),
            self::Water => __('The water'),
            self::AnglerRules => __('Before you come'),
            self::Conveniences => __('Amenities'),
            self::Map => __('Fishery map'),
            self::Gallery => __('Gallery images'),
            self::Billing => __('Settlements'),
        };
    }

    /** Liczba kolumn siatki sekcji od szerokości `md`; poniżej — jedna kolumna. */
    public function columns(): int
    {
        return match ($this) {
            self::Description, self::Map, self::Gallery, self::Conveniences => 1,
            self::Basic, self::Address, self::Billing => 2,
            self::Contact => 3,
            self::Water, self::AnglerRules => 4,
        };
    }

    /** Sekcja „Podstawowe" stoi bez ramki — to nagłówek rekordu, nie temat. */
    public function hasFrame(): bool
    {
        return $this !== self::Basic;
    }
}
