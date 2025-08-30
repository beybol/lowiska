<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Fishery;
use App\Helpers\Helper;

test('Owner panel is accessible.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);

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

test('Admin has access to owner panel.', function () {
    $admin = $this->createSuperAdmin();

    $this->actingAs($admin)
        ->get('/owner')
        ->assertStatus(200)
        ->assertSee(__('Panel'));
});

test('Owner can view only his company.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
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
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $fishery = Fishery::factory()->forUser($owner)->create();
    $otherUser = User::factory()->create();
    $otherFishery = Fishery::factory()->forUser($otherUser)->create();

    $this->actingAs($owner)
        ->get('/owner/fisheries')
        ->assertStatus(200)
        ->assertSee($fishery->name)
        ->assertDontSee($otherFishery->name);
});

test('Owner with company can view first wizard fishery step.', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $company = Company::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get('/owner/companies/create?wizard=1')
        ->assertStatus(200)
        ->assertSee(__('Create fishery wizard'))
        ->assertSeeText(__('Step') . ' 1 / 3')
        ->assertSee(__('Before creating a fishery, you should choose or create a company.'))
        ->assertDontSee(__('Before creating a fishery, you should create a company.'))
        ->assertSee(__('Next'))
        ->assertSee(__('Cancel'))
        ->assertSee(__('Or create a new company below.'))
        ->assertSee(__('Get data from CSO'));
});

test('Owner without company can not view top choose company form.', function () {
    $user = User::factory()->create();
    Helper::addOwnerRole($user);

    $this->actingAs($user)
        ->get('/owner/companies/create?wizard=1')
        ->assertStatus(200)
        ->assertSee(__('Create fishery wizard'))
        ->assertSeeText(__('Step') . ' 1 / 3')
        ->assertDontSee(__('Before creating a fishery, you should choose or create a company.'))
        ->assertSee(__('Before creating a fishery, you should create a company.'))
        ->assertSee(__('Next'))
        ->assertSee(__('Cancel'))
        ->assertDontSee(__('Or create a new company below.'))
        ->assertSee(__('Get data from CSO'));
});

test('Owner can see company verification screen', function () {
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    $company = Company::factory()->forUser($owner)->create();

    $this->actingAs($owner)
        ->get('/owner/verify-company?company=' . $company->id)
        ->assertStatus(200)
        ->assertSee(__('Create fishery wizard'))
        ->assertSeeText(__('Step') . ' 2 / 3')
        ->assertSee(__('Verification transfer'))
        ->assertSee(__('Transfer for 1 złoty is required to verify company.'))
        ->assertSee(__('Next'))
        ->assertSee(__('Previous'));
});
