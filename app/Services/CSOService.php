<?php

namespace App\Services;

use GusApi\Exception\InvalidUserKeyException;
use GusApi\Exception\NotFoundException;
use GusApi\GusApi;
use GusApi\ReportTypes;
use GusApi\BulkReportTypes;
use App\Models\State;

class CSOService
{
    public static function fetchAddress(?string $tin): array
    {
        if (empty(trim($tin))) {
            return ['error' => __('Please enter TIN.')];
        }

        $cso = new GusApi(env('CSO_Key'));
        $pureTin = str_replace('-', '', $tin);

        try {
            $cso->login();
            $gusReport = $cso->getByNip($pureTin)[0];
            $address = [];

            if (!empty($gusReport)) {
                $address = [
                    'tin' => $tin,
                    'renae' => $gusReport->getRegon(),
                    'name' => $gusReport->getName(),
                    'state' => $gusReport->getProvince(),
                    'city' => $gusReport->getCity(),
                    'street' => $gusReport->getStreet(),
                    'postal_code' => $gusReport->getZipCode(),
                    'house_number' => $gusReport->getPropertyNumber(),
                ];
                $localStateName = mb_strtolower(
                    $gusReport->getProvince(), 
                    'UTF-8',
                );
                $jsonPath = lang_path('pl.json');
                $translations = json_decode(
                    file_get_contents($jsonPath),
                    true,
                );
                $flipped = array_flip($translations);
                $englishKey = $flipped[$localStateName];

                if ($englishKey) {
                    $state = State
                        ::getByName($englishKey)
                        ->first();

                    if ($state) {
                        $address['state_id'] = $state->id;
                    }
                }

                $flatNumber = $gusReport->getApartmentNumber();

                if ($flatNumber) {
                    $address['flat_number'] = $flatNumber;
                }

                $address['cso_response'] = collect($address)
                    ->map(fn ($value, $key) => "$key: $value")
                    ->implode(', ');
            }

            return $address;
        } catch (InvalidUserKeyException $e) {
            return ['error' => __('Service key is invalid.')];
        } catch (NotFoundException $e) {
            return ['error' => __('Company not found.')];
        }
    }
}

