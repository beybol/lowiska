<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password'),
    ]);

    $this->assertTrue(Hash::check('password', $user->password));
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password'),
    ]);

    $this->assertFalse(Hash::check('wrong-password', $user->password));
});
