<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\ManageRefundPolicy;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\StayFixtures;

/**
 * Ekran „Polityka zwrotu" (zadanie 021).
 */
beforeEach(function () {
    Filament::setCurrentPanel('owner');
});

test('tiers are saved as whole numbers, farthest first, and logged', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageRefundPolicy::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['refund_policy' => [
            ['days' => '3', 'percent' => '50'],
            ['days' => '7', 'percent' => '100'],
            ['days' => '0', 'percent' => '0'],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->refund_policy)->toBe([
        ['days' => 7, 'percent' => 100],
        ['days' => 3, 'percent' => 50],
        ['days' => 0, 'percent' => 0],
    ]);

    $entry = Activity::query()->where('subject_id', $fishery->id)->where('subject_type', $fishery->getMorphClass())->latest('id')->first();

    expect($entry?->attribute_changes['attributes'])->toHaveKey('refund_policy');
});

test('no tiers is saved as a policy not set and shown as such', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition(['refund_policy' => [['days' => 7, 'percent' => 100]]]);
    $this->actingAs($owner);

    Livewire::test(ManageRefundPolicy::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['refund_policy' => []])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee(__('The refund policy is not set'));

    expect($fishery->fresh()->refund_policy)->toBeNull();
});

test('a policy that never refunds is saved, with a warning', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    Livewire::test(ManageRefundPolicy::class, ['record' => $fishery->getRouteKey()])
        ->fillForm(['refund_policy' => [['days' => '7', 'percent' => '0']]])
        ->assertSee(__('This policy never refunds anything'))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fishery->fresh()->refund_policy)->toBe([['days' => 7, 'percent' => 0]]);
});

test('duplicate days and a refund growing towards the stay are refused', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    foreach ([
        [['days' => '7', 'percent' => '100'], ['days' => '7', 'percent' => '50']],
        [['days' => '7', 'percent' => '50'], ['days' => '3', 'percent' => '100']],
    ] as $tiers) {
        Livewire::test(ManageRefundPolicy::class, ['record' => $fishery->getRouteKey()])
            ->fillForm(['refund_policy' => $tiers])
            ->call('save')
            ->assertHasFormErrors(['refund_policy']);
    }

    expect($fishery->fresh()->refund_policy)->toBeNull();
});

test('the refund policy of another fishery is not found', function () {
    [, , $attacker] = StayFixtures::fisheryWithPosition();
    [$theirs] = StayFixtures::fisheryWithPosition();
    $this->actingAs($attacker);

    $this->get(FisheryResource::getUrl('refund-policy', ['record' => $theirs], panel: 'owner'))->assertNotFound();
});

test('the sub-navigation puts the refund policy after the calendar and documents last', function () {
    [$fishery, , $owner] = StayFixtures::fisheryWithPosition();
    $this->actingAs($owner);

    $page = Livewire::test(ManageRefundPolicy::class, ['record' => $fishery->getRouteKey()])->instance();
    $labels = collect(FisheryResource::getRecordSubNavigation($page))
        ->map(fn ($item): string => (string) $item->getLabel())
        ->values()
        ->all();

    $calendar = array_search(__('Calendar'), $labels, true);

    expect($labels[$calendar + 1])->toBe(__('Refund policy'))
        ->and(end($labels))->toBe(__('Documents'));
});
