<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password')
    ]);
    $credentials = [
        'email' => $user->email,
        'password' => 'password',
    ];
    
    $this->assertTrue(Auth::attempt($credentials));
    $this->assertAuthenticatedAs($user);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password')
    ]);

    $credentials = [
        'email' => $user->email,
        'password' => 'wrong-password',
    ];

    $this->assertFalse(Auth::attempt($credentials));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    
    $this->assertAuthenticated();

    Auth::logout();
    
    $this->assertGuest();
});
