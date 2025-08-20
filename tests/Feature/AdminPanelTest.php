<?php

namespace Tests\Feature;

use App\Models\User;

test('Admin panel is accessible.', function () {
    $admin = User::factory()->create(['is_admin' => 1]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertStatus(200)
        ->assertSee(__('Panel'));
});

test('Other user can not have access to admin panel.', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});