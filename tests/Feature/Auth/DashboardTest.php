<?php

namespace Tests\Feature\Auth;

use App\Models\User;

test('dashboard screen can be rendered', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertSee(__('Dashboard'))
        ->assertSee(__('Register fishery'));
});
