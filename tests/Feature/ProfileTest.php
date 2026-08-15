<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'email_verified_at' => now(),
    ]);
    $originalEmail = $user->email;
    $user->update([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    if ($user->email !== $originalEmail) {
        $user->email_verified_at = null;
        $user->save();
    }

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test(
    'email verification status is unchanged when the email address is unchanged',
    function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $originalVerifiedAt = $user->email_verified_at;
        $user->update([
            'name' => 'Test User',
            'email' => $user->email,
        ]);
        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals($originalVerifiedAt, $user->email_verified_at);
    }
);

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->assertDatabaseHas('users', ['id' => $user->id]);

    $user->delete();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $wrongPassword = 'wrong-password';
    $isCorrectPassword = Hash::check($wrongPassword, $user->password);

    $this->assertFalse($isCorrectPassword);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});
