<?php

namespace Tests\Unit;

use App\Rules\IbanValidation;

test('IbanValidation rule validates IBAN correctly', function () {
    $validIban = 'PL26 2030 0003 0002 0001 1111 1001';
    $invalidIban = 'PL26 2030 0003 0002 0001 1111 1002';
    $emptyIban = '';
    $onlySpacesIban = '     ';
    $shortIban = 'PL26 2030 0003';
    $longIban = 'PL26 2030 0003 0002 0001 1111 1001 1234 5678';
    $invalidFormatIban = '1234567890123456789012345678901234';
    $invalidCountryCodeIban = 'ZZ26 2030 0003 0002 0001 1111 1001';

    expect(IbanValidation::isValidIban($validIban))->toBeTrue();
    expect(IbanValidation::isValidIban($invalidIban))->toBeFalse();
    expect(IbanValidation::isValidIban($emptyIban))->toBeFalse();
    expect(IbanValidation::isValidIban($onlySpacesIban))->toBeFalse();
    expect(IbanValidation::isValidIban($shortIban))->toBeFalse();
    expect(IbanValidation::isValidIban($longIban))->toBeFalse();
    expect(IbanValidation::isValidIban($invalidFormatIban))->toBeFalse();
    expect(IbanValidation::isValidIban($invalidCountryCodeIban))->toBeFalse();
});
