<?php

namespace Tests\Feature;

use App\Enums\SignInMethod;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use App\Services\HasPasswordBackfill;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Logowanie przez dostawcę (Google) i dowiązanie do istniejącego konta — zadanie 028, ADR-019.
 *
 * ⚠️ Wszystkie scenariusze podmieniają sterownik Socialite (`Socialite::fake()`), więc nie ma żądań
 * do Google. Kontroler czyta SUROWĄ odpowiedź dostawcy (`->user()->user`), stąd `setRaw()`.
 *
 * ⚠️ Testy pilnują warunków bezpieczeństwa z ADR-019: dowiązanie wyłącznie przy JAWNIE potwierdzonym
 * adresie, jedno powiązanie na konto i 2FA po każdym zalogowaniu przez dostawcę.
 */
beforeEach(function () {
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $raw
 */
function fakeGoogle(string $email, string $id = 'google-123', array $raw = [], array $without = []): void
{
    $payload = array_diff_key(array_merge([
        'sub' => $id,
        'id' => $id,
        'email' => $email,
        'email_verified' => true,
        'given_name' => 'Jan',
        'family_name' => 'Kowalski',
    ], $raw), array_flip($without));

    $user = (new SocialiteUser)
        ->setRaw($payload)
        ->map(['id' => $id, 'email' => $email, 'name' => 'Jan Kowalski']);

    Socialite::fake('google', $user);
}

function googleCallback()
{
    return test()->get(route('social.callback', ['provider' => 'google']));
}

test('a new address creates an owner account signed in with Google, without a known password', function () {
    fakeGoogle('nowy@example.com');

    googleCallback()->assertRedirect(route('verify.index'));

    $user = User::query()->where('email', 'nowy@example.com')->sole();

    expect($user->provider)->toBe('google')
        ->and($user->provider_id)->toBe('google-123')
        ->and($user->has_password)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('owner'))->toBeTrue()
        ->and(SignInMethod::of($user))->toBe(SignInMethod::Provider);

    Notification::assertSentTo($user, SendTwoFactorCode::class);
});

test('the same Google identity signs in to the same account without granting the role again', function () {
    fakeGoogle('nowy@example.com');
    googleCallback();
    $user = User::query()->where('email', 'nowy@example.com')->sole();
    $user->syncRoles([]);
    auth()->logout();

    fakeGoogle('nowy@example.com');
    // Kod 2FA z pierwszego logowania jest jeszcze niewykorzystany, więc kontroler kieruje na
    // pulpit, a tam zatrzymuje go middleware 2FA — zachowanie sprzed zadania 028.
    googleCallback()->assertRedirect();

    expect(auth()->id())->toBe($user->id)
        ->and(User::query()->where('email', 'nowy@example.com')->count())->toBe(1)
        ->and($user->fresh()->hasRole('owner'))->toBeFalse();
});

/**
 * ⚠️ Sedno zadania: konto hasłowe ZWERYFIKOWANE staje się hybrydowe — Google dowiązany, hasło działa.
 */
test('a verified password account gets Google linked and keeps its password', function () {
    $user = User::factory()->create(['email' => 'jan@example.com', 'password' => 'haslo-jana-123']);
    fakeGoogle('jan@example.com');

    googleCallback()->assertRedirect(route('verify.index'))->assertSessionMissing('status');

    $user->refresh();

    expect(User::query()->where('email', 'jan@example.com')->count())->toBe(1)
        ->and($user->provider)->toBe('google')
        ->and($user->provider_id)->toBe('google-123')
        ->and($user->has_password)->toBeTrue()
        ->and(Hash::check('haslo-jana-123', $user->password))->toBeTrue()
        ->and(SignInMethod::of($user))->toBe(SignInMethod::ProviderAndPassword);

    // 2FA obowiązuje także po dowiązaniu.
    Notification::assertSentTo($user, SendTwoFactorCode::class);
    expect(auth()->id())->toBe($user->id);
});

/**
 * Konto NIEZWERYFIKOWANE było martwe dla swojego twórcy (oba panele wymagają weryfikacji) — przejmuje
 * je właściciel skrzynki, a stare hasło przestaje działać.
 */
test('an unverified password account is taken over by the mailbox owner', function () {
    $user = User::factory()->unverified()->create(['email' => 'jan@example.com', 'password' => 'haslo-intruza-1']);
    fakeGoogle('jan@example.com');

    googleCallback()
        ->assertRedirect(route('verify.index'))
        ->assertSessionHas('status', fn (?string $status): bool => str_contains((string) $status, 'Google'));

    $user->refresh();

    expect($user->provider)->toBe('google')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->has_password)->toBeFalse()
        ->and(Hash::check('haslo-intruza-1', $user->password))->toBeFalse();
});

test('the takeover message is shown on the two-factor screen', function () {
    User::factory()->unverified()->create(['email' => 'jan@example.com']);
    fakeGoogle('jan@example.com');

    $this->followingRedirects();

    googleCallback()->assertSee('Google')->assertSee(__('Enter verification code'));
});

/**
 * ⚠️ Konto z OCZEKUJĄCYM kodem 2FA (np. po logowaniu hasłem) nie dostaje nowego kodu — a komunikat
 * o przejęciu i tak ma trafić na ekran 2FA, nie zginąć przy przekierowaniu z pulpitu.
 */
test('the takeover message reaches the two-factor screen also when a code is already pending', function () {
    $user = User::factory()->unverified()->create(['email' => 'jan@example.com']);
    $user->generateTwoFactorCode();
    fakeGoogle('jan@example.com');

    googleCallback()
        ->assertRedirect(route('verify.index'))
        ->assertSessionHas('status', fn (?string $status): bool => str_contains((string) $status, 'Google'));
});

test('linking needs the provider to confirm the address explicitly', function (array $raw, array $without) {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    fakeGoogle('jan@example.com', raw: $raw, without: $without);

    googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect($user->fresh()->provider)->toBeNull()
        ->and(auth()->check())->toBeFalse();
})->with([
    'address not confirmed' => [['email_verified' => false], []],
    // ⚠️ Brak KLUCZA, nie `null`: wartość `null` odrzuca już ogólny warunek na początku callbacku,
    // a ten przypadek (dostawca w ogóle się nie wypowiada, jak Facebook) łapie wyłącznie
    // warunek dowiązania z ADR-019.
    'provider silent about it' => [[], ['email_verified']],
]);

test('an address linked to another provider is refused', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    $user->forceFill(['provider' => 'facebook', 'provider_id' => 'fb-1'])->save();
    fakeGoogle('jan@example.com');

    googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect($user->fresh()->provider)->toBe('facebook')
        ->and(auth()->check())->toBeFalse();
});

test('the address is matched regardless of letter case', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    fakeGoogle('Jan@Example.COM');

    googleCallback()->assertRedirect(route('verify.index'));

    expect($user->fresh()->provider)->toBe('google')
        ->and(User::query()->count())->toBe(1);
});

test('an admin account links on the same rules and still needs the second factor', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
    fakeGoogle('admin@example.com');

    googleCallback()->assertRedirect(route('verify.index'));

    expect($admin->fresh()->provider)->toBe('google')
        ->and($admin->fresh()->two_factor_code)->not->toBeNull();

    Notification::assertSentTo($admin, SendTwoFactorCode::class);
});

test('linking is logged with the provider name only, without the provider id', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    fakeGoogle('jan@example.com', 'google-secret-id');

    googleCallback();

    $entry = Activity::query()
        ->where('subject_type', $user->getMorphClass())
        ->where('subject_id', $user->id)
        ->get()
        ->first(fn (Activity $activity): bool => isset($activity->attribute_changes['attributes']['provider']));

    expect($entry?->attribute_changes['old']['provider'])->toBeNull()
        ->and($entry?->attribute_changes['attributes']['provider'])->toBe('google')
        ->and(json_encode($entry?->attribute_changes))->not->toContain('google-secret-id')
        ->and($entry?->causer_id)->toBe($user->id);
});

/*
 * `has_password` i lista kont w `/admin`.
 */

test('setting a password marks it as known, a random one from the provider does not', function () {
    fakeGoogle('nowy@example.com');
    googleCallback();
    $user = User::query()->where('email', 'nowy@example.com')->sole();

    expect($user->has_password)->toBeFalse();

    $user->update(['password' => 'nowe-haslo-123']);

    expect($user->fresh()->has_password)->toBeTrue()
        ->and(SignInMethod::of($user->fresh()))->toBe(SignInMethod::ProviderAndPassword);
});

test('the filter offers the same labels as the column', function () {
    expect(SignInMethod::options())->toBe([
        SignInMethod::Password->value => __('Password'),
        SignInMethod::Provider->value => 'Google',
        SignInMethod::ProviderAndPassword->value => __(':provider + password', ['provider' => 'Google']),
    ]);
});

test('the admin user list shows and filters the sign-in method', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->createSuperAdmin());

    $passwordOnly = User::factory()->create(['email' => 'haslo@example.com']);
    $googleOnly = User::factory()->create(['email' => 'google@example.com']);
    $googleOnly->forceFill(['provider' => 'google', 'provider_id' => 'g-1', 'has_password' => false])->save();
    $hybrid = User::factory()->create(['email' => 'oba@example.com']);
    $hybrid->forceFill(['provider' => 'google', 'provider_id' => 'g-2'])->save();

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('sign_in_method', __('Password'), $passwordOnly)
        ->assertTableColumnStateSet('sign_in_method', 'Google', $googleOnly)
        ->assertTableColumnStateSet('sign_in_method', __(':provider + password', ['provider' => 'Google']), $hybrid)
        ->filterTable('sign_in_method', SignInMethod::Provider->value)
        ->assertCanSeeTableRecords([$googleOnly])
        ->assertCanNotSeeTableRecords([$passwordOnly, $hybrid])
        ->filterTable('sign_in_method', SignInMethod::ProviderAndPassword->value)
        ->assertCanSeeTableRecords([$hybrid])
        ->assertCanNotSeeTableRecords([$passwordOnly, $googleOnly])
        ->filterTable('sign_in_method', SignInMethod::Password->value)
        ->assertCanSeeTableRecords([$passwordOnly])
        ->assertCanNotSeeTableRecords([$googleOnly, $hybrid]);
});

/**
 * Reset hasła przez „Nie pamiętasz hasła?" (Breeze, `forceFill`) — hasło ustawia człowiek, więc
 * konto założone przez Google staje się „Google + hasło".
 */
test('resetting the password of a Google-only account marks the password as known', function () {
    $user = User::factory()->create(['email' => 'google@example.com']);
    $user->forceFill(['provider' => 'google', 'provider_id' => 'g-9', 'has_password' => false])->save();

    $token = Password::createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'google@example.com',
        'password' => 'Nowe-Haslo-123!',
        'password_confirmation' => 'Nowe-Haslo-123!',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->has_password)->toBeTrue()
        ->and(Hash::check('Nowe-Haslo-123!', $user->fresh()->password))->toBeTrue()
        ->and(SignInMethod::of($user->fresh()))->toBe(SignInMethod::ProviderAndPassword);
});

test('the backfill marks existing provider accounts as without a known password', function () {
    $google = User::factory()->create();
    $google->forceFill(['provider' => 'google', 'provider_id' => 'g-10'])->save();
    $password = User::factory()->create();

    // Stan sprzed migracji: kolumna ma wartość domyślną `true` dla wszystkich.
    expect($google->fresh()->has_password)->toBeTrue();

    expect(HasPasswordBackfill::run())->toBe(1)
        ->and($google->fresh()->has_password)->toBeFalse()
        ->and($password->fresh()->has_password)->toBeTrue();
});

/*
 * Testy dopisane po mutacjach przeglądu T028 — kontrakt kontrolera sprzed dowiązania, dotąd bez testu.
 */

test('a new account is refused when the provider says the address is not confirmed', function () {
    fakeGoogle('nowy@example.com', raw: ['email_verified' => false]);

    googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'nowy@example.com')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

test('a provider answer without an id or an address is refused', function (array $without) {
    fakeGoogle('nowy@example.com', without: $without);

    googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
})->with([
    'no id' => [['id']],
    'no address' => [['email']],
]);

test('a new account takes the first and last name from Google', function () {
    fakeGoogle('nowy@example.com', raw: ['given_name' => 'Anna', 'family_name' => 'Nowak']);

    googleCallback();

    $user = User::query()->where('email', 'nowy@example.com')->sole();

    expect($user->name)->toBe('Anna')
        ->and($user->surname)->toBe('Nowak');
});

/**
 * ⚠️ Ochrona przed fiksacją sesji (zadanie 012): identyfikator sesji zmienia się po zalogowaniu
 * przez dostawcę, tak jak przy logowaniu hasłem w Breeze.
 */
test('signing in with the provider regenerates the session id', function () {
    fakeGoogle('nowy@example.com');
    $this->withSession(['social_auth_source' => 'breeze']);
    $before = session()->getId();

    googleCallback();

    expect(session()->getId())->not->toBe($before);
});

test('the panel the user came from is remembered for the redirect after the second factor', function () {
    fakeGoogle('nowy@example.com');

    $this->withSession(['social_auth_source' => 'filament_owner']);

    googleCallback()->assertRedirect(route('verify.index'));

    expect(session('two_factor_source'))->toBe('filament_owner')
        ->and(session()->has('social_auth_source'))->toBeFalse();
});

test('after a takeover with a pending code the panel is remembered as well', function () {
    $user = User::factory()->unverified()->create(['email' => 'jan@example.com']);
    $user->generateTwoFactorCode();
    fakeGoogle('jan@example.com');

    $this->withSession(['social_auth_source' => 'filament_owner']);

    googleCallback()->assertRedirect(route('verify.index'));

    expect(session('two_factor_source'))->toBe('filament_owner');
});

test('with a pending code and nothing to report the user goes to where they came from', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    $user->forceFill(['provider' => 'google', 'provider_id' => 'google-123'])->save();
    $user->generateTwoFactorCode();

    fakeGoogle('jan@example.com');
    $this->withSession(['social_auth_source' => 'breeze']);
    googleCallback()->assertRedirect(route('dashboard'));

    auth()->logout();

    fakeGoogle('jan@example.com');
    $this->withSession(['social_auth_source' => 'owner']);
    googleCallback()->assertRedirect(Filament::getPanel('owner')->getUrl());
});

/**
 * ⚠️ Kolacja kolumny ignoruje akcenty — adres różniący się znakiem diakrytycznym to INNA skrzynka
 * i nie może zająć cudzego konta (security-review T028).
 */
test('an address differing only by an accent does not take over another account', function () {
    $user = User::factory()->unverified()->create(['email' => 'jose@example.com', 'password' => 'haslo-jose-123']);
    fakeGoogle('josé@example.com');

    googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $user->refresh();

    expect($user->provider)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('haslo-jose-123', $user->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});
