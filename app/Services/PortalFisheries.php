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
 * ⚠️ Karta NIE liczy ceny ani sprzedawalności — „cena od" dochodzi w 032 z warstwy oferty,
 * linijka zasad w 033 (`strona-publiczna.md`).
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
            ->with(['state', 'fisheryTypes'])
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
     * @return array{name: string, url: string|null, water: string, state: string, positions: int, no_kill: bool}
     */
    public static function card(Fishery $fishery): array
    {
        $type = $fishery->fisheryTypes->first();
        $water = array_filter([
            $type !== null ? __($type->name) : null,
            filled($fishery->area) ? Number::format((float) $fishery->area, maxPrecision: 2, locale: app()->getLocale()).' ha' : null,
        ]);

        return [
            'name' => $fishery->name,
            'url' => PortalRoutes::fisheryUrl($fishery),
            'water' => implode(' ', $water),
            'state' => $fishery->state !== null ? __($fishery->state->name) : '',
            'positions' => (int) ($fishery->positions_for_sale_count ?? 0),
            // ⚠️ Trzeci stan: `null` („nie podano") NIE jest „nie" — plakietka tylko przy jawnym „tak".
            'no_kill' => $fishery->no_kill === true,
        ];
    }
}
