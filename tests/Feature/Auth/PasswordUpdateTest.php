<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password')
    ]);
    $oldPassword = $user->password;
    
    $this->assertTrue(Hash::check('password', $oldPassword));
    
    $user->password = Hash::make('new-password');
    $user->save();
    $user->refresh();

    $this->assertTrue(Hash::check('new-password', $user->password));
    $this->assertFalse(Hash::check('password', $user->password));
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password')
    ]);

    $this->assertTrue(Hash::check('password', $user->password));
    $this->assertFalse(Hash::check('wrong-password', $user->password));
});
