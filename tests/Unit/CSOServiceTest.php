<?php

namespace Tests\Unit;

use App\Services\CSOService;

test('CSOService validates TIN correctly', function () {
    $validTin = '7822007919';
    $invalidTin = '123456789';
    $emptyTIN = '';
    $onlySpacesTIN = '     ';
    $lettersTIN = '7822A07919';

    expect(CSOService::isValidTIN($validTin))->toBeTrue();
    expect(CSOService::isValidTIN($invalidTin))->toBeFalse();
    expect(CSOService::isValidTIN($emptyTIN))->toBeFalse();
    expect(CSOService::isValidTIN($onlySpacesTIN))->toBeFalse();
    expect(CSOService::isValidTIN($lettersTIN))->toBeFalse();
});

test('CSOService validates RENAE correctly', function () {
    $validRenae = '123456789';
    $validLongRenae = '12345678901234';
    $invalidRenae = '12345678';
    $emptyRenae = '';
    $onlySpacesRenae = '     ';
    $lettersRenae = '12345678A';

    expect(CSOService::isValidRENAE($validRenae))->toBeTrue();
    expect(CSOService::isValidRENAE($validLongRenae))->toBeTrue();
    expect(CSOService::isValidRENAE($invalidRenae))->toBeFalse();
    expect(CSOService::isValidRENAE($emptyRenae))->toBeFalse();
    expect(CSOService::isValidRENAE($onlySpacesRenae))->toBeFalse();
    expect(CSOService::isValidRENAE($lettersRenae))->toBeFalse();
});