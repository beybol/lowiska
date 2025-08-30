<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Fishery;
use App\Helpers\Helper;

test('Owner panel is accessible.', function () {
    $user = User::factory()->create();
    Helper::addOwnerRole($user);

    $this->actingAs($user)
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

test('Admin has access to owner panel.', function () {
    $admin = $this->createSuperAdmin();

    $this->actingAs($admin)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Panel'));
});

test('Owner can view only his company.', function () {
    $user = User::factory()->create();
    Helper::addOwnerRole($user);
    $company = Company::factory()->forUser($user)->create();
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->forUser($otherUser)->create();

    $this->actingAs($user)
        ->get('/owner/companies')
        ->assertStatus(200)
        ->assertSee($company->name)
        ->assertDontSee($otherCompany->name);
});

test('Owner can view only his fishery.', function () {
    $user = User::factory()->create();
    Helper::addOwnerRole($user);
    $fishery = Fishery::factory()->forUser($user)->create();
    $otherUser = User::factory()->create();
    $otherFishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($user)
        ->get('/owner/fisheries')
        ->assertStatus(200)
        ->assertSee($fishery->name)
        ->assertDontSee($otherFishery->name);
});
