<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Models\Fishery;
use App\Services\PortalSlugs;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Slug łowiska jako krótki adres wprost pod domeną — ochrona przed kolizją z trasami (ADR-021, B).
 *
 * Warstwy: kształt (bez kropki, minimum 3 znaki), lista zastrzeżona, pierwsze segmenty tras
 * z routera; przy wdrożeniu — `portal:check-slugs`.
 */
test('a slug can not take a reserved name, a route segment, a short code or a dotted name', function (string $slug) {
    expect(PortalSlugs::collidesWithApplication($slug))->toBeTrue();
})->with([
    'panel' => 'admin',
    'owner panel' => 'owner',
    'public directory' => 'build',
    'reserved for later' => 'api',
    'breeze route' => 'forgot-password',
    'language code' => 'pl',
    'two characters' => 'ab',
    'dot' => 'robots.txt',
    'uppercase' => 'Klasztorne',
]);

test('a regular fishery slug is free', function () {
    expect(PortalSlugs::collidesWithApplication('klasztorne'))->toBeFalse()
        ->and(PortalSlugs::collidesWithApplication('lowisko-2'))->toBeFalse();
});

test('the first segments of registered routes are reserved even without a list entry', function () {
    // `verify` jest na liście, ale `livewire-…` (prefiks z haszem) zna wyłącznie router.
    $livewire = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => explode('/', $route->uri())[0])
        ->first(fn (string $segment) => str_starts_with($segment, 'livewire-'));

    expect($livewire)->not->toBeNull()
        ->and(PortalSlugs::collidesWithApplication($livewire))->toBeTrue();
});

test('an automatic slug that would collide gets a suffix', function () {
    expect(Fishery::factory()->create(['name' => 'Admin'])->slug)->toBe('admin-2')
        ->and(Fishery::factory()->create(['name' => 'Login'])->slug)->toBe('login-2');
});

test('the fishery name needs at least three characters', function () {
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');
    $fishery = Fishery::factory()->create(['name' => 'Klasztorne']);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->fillForm(['name' => 'Ab'])
        ->call('save')
        ->assertHasFormErrors(['name' => 'min']);
});

test('the admin can not set a slug taken by the application', function (string $slug) {
    $this->actingAs($this->createSuperAdmin());
    Filament::setCurrentPanel('admin');
    $fishery = Fishery::factory()->create(['name' => 'Klasztorne']);

    Livewire::test(EditFishery::class, ['record' => $fishery->getKey()])
        ->fillForm(['slug' => $slug])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($fishery->fresh()->slug)->toBe('klasztorne');
})->with(['owner', 'login', 'ab']);

test('portal:check-slugs warns about a colliding slug and never fails', function () {
    $fishery = Fishery::factory()->create(['name' => 'Klasztorne']);
    DB::table('fisheries')->where('id', $fishery->id)->update(['slug' => 'login']);

    $exit = Artisan::call('portal:check-slugs');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain("Fishery #{$fishery->id} slug 'login'");
});

test('portal:check-slugs reports a clean database', function () {
    Fishery::factory()->create(['name' => 'Klasztorne']);

    $this->artisan('portal:check-slugs')
        ->expectsOutput('No fishery slug collides with the application.')
        ->assertExitCode(0);
});
