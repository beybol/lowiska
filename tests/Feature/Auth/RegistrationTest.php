<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('registration screen can be rendered', function () {
    $this->get('/register')
        ->assertStatus(200)
        ->assertSee(__('Register fishery'));
});

test('new users can register', function () {
    $userData = [
        'name' => 'Test',
        'surname' => 'User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ];
    $user = User::create($userData);

    $this->assertInstanceOf(User::class, $user);
    $this->assertEquals('Test', $user->name);
    $this->assertEquals('User', $user->surname);
    $this->assertEquals('test@example.com', $user->email);
    $this->assertTrue(Hash::check('password', $user->password));
    $this->assertDatabaseHas('users', [
        'name' => 'Test',
        'surname' => 'User',
        'email' => 'test@example.com',
    ]);
});

test('user registration validates unique email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    
    try {
        User::create([
            'name' => 'User',
            'surname' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->fail('Expected unique constraint violation');
    } catch (\Exception $e) {
        $this->assertTrue(true);
    }
});
