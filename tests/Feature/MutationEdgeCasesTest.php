<?php

namespace Tests\Feature;

use App\Models\Fishery;
use App\Models\User;
use App\Rules\FacebookUrl;
use App\Rules\FisherySlugIsNotReserved;
use App\Rules\PhoneNumber;
use App\Services\AmountFormatter;
use App\Services\OwnerRoleProvisioner;
use App\Services\PortalLocale;
use App\Services\PortalSlugs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use ReflectionProperty;
use Spatie\Permission\Models\Permission;

/**
 * Przypadki brzegowe wskazane przez testy mutacyjne (przegląd implementacji, 01.10.2026) — każdy test zabija
 * mutanta, który przeżył, bo żaden istniejący test nie sprawdzał tej granicy.
 */
function passes(object $rule, mixed $value): bool
{
    return Validator::make(['field' => $value], ['field' => [$rule]])->passes();
}

// ---------------------------------------------------------------- FisheryPolicy::delete()

test('an admin deletes any fishery, also without being its owner', function () {
    $admin = $this->createSuperAdmin();
    $fishery = Fishery::factory()->create();

    expect(Gate::forUser($admin)->allows('delete', $fishery))->toBeTrue();
});

test('a user with the delete permission but without the owner role is not limited to own fisheries', function () {
    // Granica `hasRole('owner') && ! is_admin`: ograniczenie do własnych łowisk dotyczy WYŁĄCZNIE roli właściciela.
    $user = User::factory()->create(['is_admin' => false]);
    $user->givePermissionTo(Permission::findOrCreate('delete:fishery'));
    $fishery = Fishery::factory()->create();

    expect(Gate::forUser($user->fresh())->allows('delete', $fishery))->toBeTrue();
});

test('an owner deletes only their own fishery', function () {
    $owner = User::factory()->create(['is_admin' => false]);
    OwnerRoleProvisioner::addOwnerRole($owner);

    expect(Gate::forUser($owner->fresh())->allows('delete', Fishery::factory()->forUser($owner)->create()))->toBeTrue()
        ->and(Gate::forUser($owner->fresh())->allows('delete', Fishery::factory()->create()))->toBeFalse();
});

// ---------------------------------------------------------------- reguły pól kontaktu i sluga

test('a Facebook address is accepted over http and regardless of letter case', function () {
    expect(passes(new FacebookUrl, 'http://facebook.com/klasztorne'))->toBeTrue()
        ->and(passes(new FacebookUrl, 'HTTPS://WWW.FACEBOOK.COM/klasztorne'))->toBeTrue()
        ->and(passes(new FacebookUrl, 'ftp://facebook.com/klasztorne'))->toBeFalse();
});

test('a phone number is accepted exactly at the length limit and at the minimum number of digits', function () {
    expect(passes(new PhoneNumber, str_repeat('1', PhoneNumber::MAX_LENGTH)))->toBeTrue()
        ->and(passes(new PhoneNumber, str_repeat('1', PhoneNumber::MAX_LENGTH + 1)))->toBeFalse()
        ->and(passes(new PhoneNumber, '123 456'))->toBeTrue()
        ->and(passes(new PhoneNumber, '12 345'))->toBeFalse()
        // Za długi, choć ze znakami i liczbą cyfr w porządku — długość odrzuca samodzielnie.
        ->and(passes(new PhoneNumber, '+48 '.str_repeat('1', PhoneNumber::MAX_LENGTH)))->toBeFalse();
});

test('the reserved slug message tells the minimum length', function () {
    app()->setLocale('pl');
    $validator = Validator::make(['slug' => 'admin'], ['slug' => [new FisherySlugIsNotReserved]]);

    expect($validator->errors()->first('slug'))->toContain((string) PortalSlugs::MIN_FISHERY_LENGTH);
});

// ---------------------------------------------------------------- PortalSlugs — segmenty tras

/**
 * ⚠️ Segmenty tras są buforowane statycznie w procesie — bez wyczyszczenia bufora test sprawdzałby listę policzoną
 * wcześniej przez inny test, a nie bieżące trasy (dlatego mutanty tej metody przeżywały).
 */
test('a route registered at runtime reserves its first segment, but parameters and the fallback do not', function () {
    Route::get('trasa-dodana-w-tescie/{id}', fn () => 'ok');
    Route::get('{parametr}/podstrona-testowa', fn () => 'ok');
    app('router')->getRoutes()->refreshNameLookups();
    (new ReflectionProperty(PortalSlugs::class, 'routeSegments'))->setValue(null, null);

    expect(PortalSlugs::collidesWithApplication('trasa-dodana-w-tescie'))->toBeTrue()
        ->and(PortalSlugs::collidesWithApplication('podstrona-testowa'))->toBeFalse()
        ->and(PortalSlugs::collidesWithApplication('klasztorne-jezioro'))->toBeFalse();

    (new ReflectionProperty(PortalSlugs::class, 'routeSegments'))->setValue(null, null);
});

// ---------------------------------------------------------------- AmountFormatter::forVisitor()

test('a visitor amount drops the grosze only for a whole amount', function () {
    app()->setLocale('pl');

    expect(AmountFormatter::forVisitor(7000, 'PLN'))->not->toContain(',')
        ->and(AmountFormatter::forVisitor(7050, 'PLN'))->toContain('70,50')
        ->and(AmountFormatter::forVisitor(7001, 'PLN'))->toContain('70,01')
        ->and(AmountFormatter::forVisitor(9900, 'PLN'))->not->toContain(',')
        ->and(AmountFormatter::forVisitor(10100, 'PLN'))->not->toContain(',');
});

test('a visitor amount without a currency is the bare number', function () {
    app()->setLocale('pl');

    expect(AmountFormatter::forVisitor(7050))->toBe('70,50')
        ->and(AmountFormatter::forVisitor(7000))->toBe('70')
        ->and(AmountFormatter::forVisitor(7000, 'PLN'))->toContain('zł');
});

// ---------------------------------------------------------------- PortalLocale::resolve()

test('a language cookie the portal does not have is ignored in favour of the browser language', function () {
    $request = Request::create('/', 'GET', cookies: [PortalLocale::COOKIE => 'de'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

    expect(PortalLocale::resolve($request))->toBe('en');
});
