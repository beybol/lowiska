<?php

namespace App\Helpers;

use App\Models\Country;
use Filament\Forms\Set;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

class Helper
{
    public static function setAddress(Set $set, array $address): void
    {
        if (isset($address['error'])) {
            $set('error', $address['error']);
        } else {
            $set('cso_response', $address['cso_response'] ?? null);
            $name = $address['name'] ?? null;
            $set('name', $name);
            $renae = $address['renae'] ?? null;
            $set('renae', $renae);
            $tin = $address['tin'] ?? null;
            $set('tin', $tin);
            $street = $address['street'] ?? null;
            $set('street', $street);
            $hN = $address['house_number'] ?? null;
            $set('house_number', $hN);
            $fN = $address['flat_number'] ?? null;
            $set('flat_number', $fN);
            $postalCode = $address['postal_code'] ?? null;
            $set('postal_code', $postalCode);
            $city = $address['city'] ?? null;
            $set('city', $city);
            $stateId = $address['state_id'] ?? null;
            $set('state_id', $stateId);
            $set('error', null);
        }
    }

    public static function getSortedCountries(): Collection
    {
        $collator = new \Collator(app()->getLocale());
        
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
                $label = __($country->country_name) . ", {$country->prefix}";

                return [$country->id => $label];
            })
            ->toArray();
    }

    public static function getSocialAuthActions(
        string $actionType = 'login'
    ): array
    {
        $currentPanel = Filament::getCurrentPanel();
        $panelId = $currentPanel ? $currentPanel->getId() : 'admin';
        
        return [
            Action::make($actionType . '_google')
                ->label(__(
                    $actionType === 'login' 
                        ? 'Login with Google' 
                        : 'Register with Google'
                    ))
                ->color('gray')
                ->icon(fn () => view('components.icons.google'))
                ->url(route('social.redirect', [
                    'provider' => 'google', 
                    'source' => $panelId
                ])),
            Action::make($actionType . '_facebook')
                ->label(__(
                    $actionType === 'login' 
                        ? 'Login with Facebook' 
                        : 'Register with Facebook'
                ))
                ->color('gray')
                ->icon(fn () => view('components.icons.facebook'))
                ->url(route('social.redirect', [
                    'provider' => 'facebook', 
                    'source' => $panelId
                ])),
        ];
    }

    public static function isOwnerPanel(): bool
    {
        $panel = Filament::getCurrentPanel();
        
        return $panel?->getId() === 'owner';
    }
}
