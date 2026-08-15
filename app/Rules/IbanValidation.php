<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IbanValidation implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        if (! self::isValidIban($value)) {
            $fail(__('The :attribute must be a valid IBAN number.'));
        }
    }

    public static function isValidIban(string $iban): bool
    {
        $iban = strtoupper(str_replace([' ', '-'], '', $iban));

        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        $countryCode = substr($iban, 0, 2);

        if (! self::isValidCountryCode($countryCode)) {
            return false;
        }

        $expectedLength = self::getCountryLength($countryCode);

        if ($expectedLength && strlen($iban) !== $expectedLength) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numericString = '';

        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];

            if (ctype_alpha($char)) {
                $numericString .= (ord($char) - ord('A') + 10);
            } else {
                $numericString .= $char;
            }
        }

        return bcmod($numericString, '97') === '1';
    }

    public static function isValidCountryCode(string $code): bool
    {
        $validCountries = [
            'AD',
            'AE',
            'AL',
            'AT',
            'AZ',
            'BA',
            'BE',
            'BG',
            'BH',
            'BR',
            'BY',
            'CH',
            'CR',
            'CY',
            'CZ',
            'DE',
            'DK',
            'DO',
            'EE',
            'EG',
            'ES',
            'FI',
            'FO',
            'FR',
            'GB',
            'GE',
            'GI',
            'GL',
            'GR',
            'GT',
            'HR',
            'HU',
            'IE',
            'IL',
            'IS',
            'IT',
            'JO',
            'KW',
            'KZ',
            'LB',
            'LC',
            'LI',
            'LT',
            'LU',
            'LV',
            'MC',
            'MD',
            'ME',
            'MK',
            'MR',
            'MT',
            'MU',
            'NL',
            'NO',
            'PK',
            'PL',
            'PS',
            'PT',
            'QA',
            'RO',
            'RS',
            'SA',
            'SE',
            'SI',
            'SK',
            'SM',
            'TN',
            'TR',
            'UA',
            'VG',
            'XK',
        ];

        return in_array($code, $validCountries);
    }

    public static function getCountryLength(string $code): ?int
    {
        $lengths = [
            'AD' => 24,
            'AE' => 23,
            'AL' => 28,
            'AT' => 20,
            'AZ' => 28,
            'BA' => 20,
            'BE' => 16,
            'BG' => 22,
            'BH' => 22,
            'BR' => 29,
            'BY' => 28,
            'CH' => 21,
            'CR' => 22,
            'CY' => 28,
            'CZ' => 24,
            'DE' => 22,
            'DK' => 18,
            'DO' => 28,
            'EE' => 20,
            'EG' => 29,
            'ES' => 24,
            'FI' => 18,
            'FO' => 18,
            'FR' => 27,
            'GB' => 22,
            'GE' => 22,
            'GI' => 23,
            'GL' => 18,
            'GR' => 27,
            'GT' => 28,
            'HR' => 21,
            'HU' => 28,
            'IE' => 22,
            'IL' => 23,
            'IS' => 26,
            'IT' => 27,
            'JO' => 30,
            'KW' => 30,
            'KZ' => 20,
            'LB' => 28,
            'LC' => 32,
            'LI' => 21,
            'LT' => 20,
            'LU' => 20,
            'LV' => 21,
            'MC' => 27,
            'MD' => 24,
            'ME' => 22,
            'MK' => 19,
            'MR' => 27,
            'MT' => 31,
            'MU' => 30,
            'NL' => 18,
            'NO' => 15,
            'PK' => 24,
            'PL' => 28,
            'PS' => 29,
            'PT' => 25,
            'QA' => 29,
            'RO' => 24,
            'RS' => 22,
            'SA' => 24,
            'SE' => 24,
            'SI' => 19,
            'SK' => 24,
            'SM' => 27,
            'TN' => 24,
            'TR' => 26,
            'UA' => 29,
            'VG' => 24,
            'XK' => 20,
        ];

        return $lengths[$code] ?? null;
    }
}
