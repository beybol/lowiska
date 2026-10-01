<?php

namespace App\Services;

use App\Models\Fishery;
use Collator;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Łowiska widoczne w portalu i ich karty (zadanie 031, portal-v3 §5.1).
 *
 * ⚠️ **Tylko łowiska opublikowane** (`Fishery::published()`) i tylko te z województwem — bez niego
 * strona łowiska nie ma adresu kanonicznego (ADR-021). Kolejność: **alfabetycznie po nazwie,
 * z polską kolacją** — „Jak układamy listę" obiecuje wprost, że nic poza nazwą jej nie ustawia.
 *
 * ⚠️ Karta NIE liczy ceny ani sprzedawalności — „cenę od" podaje `PriceFrom` (cennik, zadanie 032),
 * linijka zasad dochodzi w 033 (`strona-publiczna.md`).
 */
final class PortalFisheries
{
    /**
     * @return Collection<int, Fishery>
     */
    public static function listed(): Collection
    {
        $fisheries = Fishery::query()
            ->published()
            ->whereNotNull('state_id')
            // Okresy sprzedaży, cennik i terminy w całości — dla „ceny od" i linijki zasad każdej karty; bez nich
            // każda karta to kilka zapytań (038). `SaleCalendar`, `PriceFrom` i wyciąg zasad czytają wczytane relacje.
            ->with(['state', 'fisheryTypes', 'currency', 'media', 'salePeriods', 'priceRules', 'wholeTermPeriods'])
            ->withCount(['positions as positions_for_sale_count' => fn ($query) => $query->available()])
            ->get();

        $collator = new Collator('pl_PL');

        return $fisheries
            ->sort(fn (Fishery $a, Fishery $b): int => (int) $collator->compare($a->name, $b->name))
            ->values();
    }

    /**
     * Dane jednej karty listy — w kolejności z makiety.
     *
     * @return array{name: string, url: string|null, water: string, state: string, positions: int, no_kill: bool, price_from: string|null, rules: string, cover: array{src: string, srcset: string}|null}
     */
    public static function card(Fishery $fishery): array
    {
        return [
            'name' => $fishery->name,
            'url' => PortalRoutes::fisheryUrl($fishery),
            'water' => self::water($fishery),
            'state' => self::stateName($fishery),
            'positions' => (int) ($fishery->positions_for_sale_count ?? 0),
            // ⚠️ Trzeci stan: `null` („nie podano") NIE jest „nie" — plakietka tylko przy jawnym „tak".
            'no_kill' => $fishery->no_kill === true,
            // `null` = „cennik w przygotowaniu". Liczy ją wyłącznie `PriceFrom` — karta tylko formatuje.
            'price_from' => self::priceFrom($fishery),
            // Linijka zasad — ta sama metoda co wyciąg nad kalendarzem (033).
            'rules' => (new FisheryRulesSummary($fishery))->cardLine(),
            // Okładka = pierwsze zdjęcie galerii (036, R1); brak zdjęcia albo wariantu → tło motywu (R3).
            'cover' => self::cover($fishery),
        ];
    }

    /**
     * Akwen jednym ciągiem: „Jezioro rynnowe 16 ha" — wspólne dla karty listy i podtytułu strony łowiska.
     */
    public static function water(Fishery $fishery): string
    {
        $type = $fishery->fisheryTypes->first();

        return implode(' ', array_filter([
            $type !== null ? __($type->name) : null,
            // Powierzchnia 0 to brak danych, nie „0 ha" (pole jest w formularzu wymagane liczbowo).
            (float) $fishery->area > 0 ? Number::format((float) $fishery->area, maxPrecision: 2, locale: app()->getLocale()).' ha' : null,
        ]));
    }

    public static function stateName(Fishery $fishery): string
    {
        return $fishery->state !== null ? __($fishery->state->name) : '';
    }

    /**
     * @return array{src: string, srcset: string}|null
     */
    private static function cover(Fishery $fishery): ?array
    {
        $media = $fishery->getFirstMedia(FisheryImages::GALLERY);
        $src = $media !== null ? FisheryImages::url($media, 480) : null;

        // `url()` i `srcset()` czytają te same warianty — `FisheryImages` liczy je raz na zdjęcie w żądaniu.
        return $src === null ? null : ['src' => $src, 'srcset' => FisheryImages::srcset($media)];
    }

    public static function priceFrom(Fishery $fishery): ?string
    {
        $cents = (new PriceFrom($fishery))->amountInCents();

        return $cents === null ? null : AmountFormatter::forVisitor($cents, $fishery->currency?->name);
    }
}
