<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Country;
use App\Models\State;
use Collator;
use Illuminate\Support\Collection;

/**
 * Posortowane listy słownikowe do pól wyboru.
 *
 * Sortowanie idzie przez `Collator` w bieżącym locale — polskie znaki diakrytyczne
 * inaczej lądują na końcu listy.
 */
final class DictionaryOptions
{
    public static function getSortedCountries(): Collection
    {
        $collator = new Collator(app()->getLocale());

        return Country::active()
            ->get()
            ->sort(function ($country1, $country2) use ($collator) {
                return $collator->compare(
                    __($country1->country_name),
                    __($country2->country_name)
                );
            });
    }

    public static function getCountryPrefixes(): array
    {
        return self::getSortedCountries()
            ->mapWithKeys(function ($country) {
                $label = __($country->country_name).", {$country->prefix}";

                return [$country->id => $label];
            })
            ->toArray();
    }

    public static function sortStates()
    {
        $collator = new Collator(app()->getLocale());

        return State::all()
            ->sort(function ($state1, $state2) use ($collator) {
                return $collator->compare(
                    __($state1->name),
                    __($state2->name)
                );
            })
            ->pluck('name', 'id')
            ->map(fn ($name) => __($name));
    }

    public static function sortedCompanies($query = null, $modelsOnly = false)
    {
        $collator = new Collator(app()->getLocale());
        $companies = $query ?? Company::query();

        $sortedCompanies = $companies->get()
            ->sort(function ($company1, $company2) use ($collator) {
                return $collator->compare($company1->name, $company2->name);
            });

        return $modelsOnly
            ? $sortedCompanies
            : $sortedCompanies->pluck('name', 'id');
    }
}
