<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use App\Services\OwnerRoleProvisioner;
use App\Services\PanelHome;
use Illuminate\Support\Facades\Notification;

/**
 * Po logowaniu — panel zamiast pulpitu Breeze (zadanie 031, Rozstrzygnięcie 1).
 *
 * `is_admin` → panel administratora, pozostali → panel właściciela; jedna reguła w `PanelHome`.
 */
test('the panel home is the admin panel for an admin and the owner panel for everyone else', function () {
    expect(PanelHome::urlFor(User::factory()->create(['is_admin' => true])))->toBe(url('admin'))
        ->and(PanelHome::urlFor(User::factory()->create(['is_admin' => false])))->toBe(url('owner'))
        ->and(PanelHome::urlFor(null))->toBe(url('owner'));
});

test('after the Breeze two-factor step an admin lands in the admin panel and an owner in the owner panel', function (bool $isAdmin, string $panel) {
    Notification::fake();
    $user = User::factory()->create(['is_admin' => $isAdmin]);
    OwnerRoleProvisioner::addOwnerRole($user);
    $user->generateTwoFactorCode();

    $this->actingAs($user)
        ->post(route('verify.store'), ['two_factor_code' => $user->fresh()->two_factor_code])
        ->assertRedirect(url($panel));
})->with([
    'admin' => [true, 'admin'],
    'owner' => [false, 'owner'],
]);

test('a password login through Breeze goes to the two-factor step and then to the panel', function () {
    Notification::fake();
    $user = User::factory()->create(['is_admin' => true]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('verify.index'));

    Notification::assertSentTo($user, SendTwoFactorCode::class);

    $this->post(route('verify.store'), ['two_factor_code' => $user->fresh()->two_factor_code])
        ->assertRedirect(url('admin'));
});

test('a registration through Breeze lands in the owner panel', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Jan',
        'surname' => 'Rejestrowany',
        'email' => 'jan.rejestrowany@example.com',
        'password' => 'Haslo-Testowe-123',
        'password_confirmation' => 'Haslo-Testowe-123',
    ])->assertRedirect(url('owner'));
});
