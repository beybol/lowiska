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
    public static function isValidTIN(string $tin): bool
    {
        $tin = preg_replace('/[^0-9]/', '', $tin);

        if (strlen($tin) !== 10) {
            return false;
        }
        
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        
        for ($i = 0; $i < 9; $i++) {
            $sum += $tin[$i] * $weights[$i];
        }
        
        $checksum = $sum % 11;

        if ($checksum === 10) {
            return false;
        }

        return $checksum == $tin[9];
    }

    public static function isValidRENAE(string $renae): bool
    {
        $renae = preg_replace('/[^0-9]/', '', $renae);

        return in_array(strlen($renae), [9, 14]);
    }

    public static function fetchAddress(
        ?string $search,
        bool $isTin = false
    ): array
    {
        $cso = new GusApi(env('CSO_Key'));
        $pureSearch = str_replace('-', '', $search);

        try {
            $cso->login();

            if ($isTin) {
                $gusReport = $cso->getByNip($pureSearch)[0];
            } else {
                $gusReport = $cso->getByRegon($pureSearch)[0];
            }

            $address = [];

            if (!empty($gusReport)) {
                $address = [
                    'tin' => $gusReport->getNip(),
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

    public static function checkAreMatched($tin, $renae): bool
    {
        $tinAddress = self::fetchAddress($tin, true);
        
        if ($tinAddress['error']) {
            return false;
        }

        $downloadedRenae = $tinAddress['renae'];

        return $downloadedRenae === $renae;
    }
}

