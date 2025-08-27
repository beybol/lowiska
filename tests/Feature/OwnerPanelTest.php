<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Fishery;

test('Owner panel is accessible.', function () {
    $owner = $this->createOwner();

    $this->actingAs($owner)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Panel'))
        ->assertSee(__('Companies'))
        ->assertDontSee(__('Fish'))
        ->assertDontSee(__('Conveniences'))
        ->assertDontSee(__('Countries'))
        ->assertDontSee(__('Fishery types'))
        ->assertDontSee(__('Fishing methods'))
        ->assertDontSee(__('States'))
        ->assertSee(__('Fisheries'))
        ->assertDontSee(__('Users'));
});

test('Other user can not have access to owner panel.', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/owner')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});

test('Admin can not have access to owner panel.', function () {
    $admin = $this->createSuperAdmin();

    $this->actingAs($admin)
        ->get('/owner')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});

test('Owner can view only his company.', function () {
    $owner = $this->createOwner();
    $company = Company::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get('/owner/companies')
        ->assertStatus(200)
        ->assertSee($company->name)
        ->assertDontSee($otherCompany->name);
});

test('Owner can view only his fishery.', function () {
    $owner = $this->createOwner();
    $fishery = Fishery::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherFishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get('/owner/fisheries')
        ->assertStatus(200)
        ->assertSee($fishery->name)
        ->assertDontSee($otherFishery->name);
});
