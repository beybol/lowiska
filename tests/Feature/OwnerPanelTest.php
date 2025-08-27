<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;

test('Owner panel is accessible.', function () {
    $owner = $this->createOwner();

    $this->actingAs($owner)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Panel'));
});

test('Other user can not have access to owner panel.', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/owner')
        ->assertStatus(403)
        ->assertDontSee(__('Panel'));
});

test('Owner can view only his company.', function () {
    $owner = $this->createOwner();
    $company = Company::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->forUser($otherUser)->create();

    $response = $this->actingAs($owner)
        ->get('/owner/companies')
        ->assertStatus(200)
        ->assertSee($company->name)
        ->assertDontSee($otherCompany->name);
});
