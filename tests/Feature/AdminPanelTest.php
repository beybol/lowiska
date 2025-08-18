<?php

namespace Tests\Feature;

use App\Models\User;

test('Admin panel is accessible.', function () {
    $admin = User::factory()->create(['is_admin' => 1]);

    $this->actingAs($admin)->get('/admin/states')->assertStatus(200);
});

test('Other user can not have access to admin panel.', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/states')->assertStatus(403);
});