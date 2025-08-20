<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();
    $user = User::factory()->create();
    $status = Password::sendResetLink(['email' => $user->email]);

    $this->assertEquals(Password::RESET_LINK_SENT, $status);
    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    $this->get('/reset-password/fake-token')->assertStatus(200);
});

test('password can be reset with valid token', function () {
    $user = User::factory()->create([
        'password' => bcrypt('old-password')
    ]);
    $token = Password::createToken($user);
    $status = Password::reset([
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
        'token' => $token,
    ], function ($user, $password) {
        $user->password = Hash::make($password);
        $user->save();
    });

    $this->assertEquals(Password::PASSWORD_RESET, $status);
    
    $user->refresh();
    
    $this->assertTrue(Hash::check('new-password', $user->password));
});
