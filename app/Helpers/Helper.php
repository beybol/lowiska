<?php

namespace App\Helpers;

use Filament\Forms\Set;

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
}
