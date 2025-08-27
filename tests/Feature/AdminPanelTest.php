<?php

namespace Tests\Feature;

use App\Models\User;

test('Admin panel is accessible.', function () {
    $superAdmin = $this->createSuperAdmin();

    $this->actingAs($superAdmin)
        ->get('/admin')
        ->assertStatus(200)
        ->assertSee(__('Panel'))
        ->assertSee(__('Companies'))
        ->assertSee(__('Fish'))
        ->assertSee(__('Conveniences'))
        ->assertSee(__('Countries'))
        ->assertSee(__('Fishery types'))
        ->assertSee(__('Fishing methods'))
        ->assertSee(__('States'))
        ->assertSee(__('Fisheries'))
        ->assertSee(__('Users'));
});

test('Other user can not have access to admin panel.', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});

test('Owner can not have access to admin panel.', function () {
    $owner = $this->createOwner();

    $this->actingAs($owner)
        ->get('/admin')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});