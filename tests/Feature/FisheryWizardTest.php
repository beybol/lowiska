<?php

namespace Tests\Feature;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Fishery;
use App\Models\State;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Kreator zakładania łowiska w panelu właściciela (zadanie 012, ADR-006).
 *
 * Te testy zastępują osiem testów z `OwnerPanelTest.php`, które asertowały
 * poprzedni, URL-owy przepływ (`?wizard=1`, strona `verify-company`, „Step 1 / 3",
 * `verified_earlier=1`). Przepływ zniknął wraz z refaktorem na natywny `Wizard`,
 * ale pokrywane przez nie **przypadki brzegowe zostają** — właściciel bez firmy,
 * firma zweryfikowana wcześniej, brak kreatora poza panelem właściciela.
 *
 * ⚠️ `Livewire::test()` montuje komponent POZA kontekstem panelu, więc
 * `Filament::getCurrentOrDefaultPanel()` zwraca panel domyślny (`admin`)
 * i `Helper::isOwnerPanel()` jest wtedy fałszem — kreator by się nie pokazał.
 * Stąd jawne `Filament::setCurrentPanel('owner')` w każdym teście; bez tego
 * testowalibyśmy płaski formularz administratora, myśląc, że to kreator.
 */
function wizardOwner(): User
{
    $owner = User::factory()->create();
    Helper::addOwnerRole($owner);
    test()->actingAs($owner);
    Filament::setCurrentPanel('owner');

    return $owner;
}

function wizardFisheryData(State $state): array
{
    return [
        'name' => 'Testowe łowisko',
        'state_id' => $state->id,
        'town' => 'Warszawa',
        'street' => 'Kwiatowa',
        'building_number' => '12',
        'zip_code' => '00-001',
        'area' => 5,
        'positions_count' => 10,
    ];
}

/**
 * Etykiety kroków kreatora w kolejności, w jakiej renderuje je nagłówek `Wizard`.
 *
 * @return array<int, string>
 */
function wizardStepLabels(string $html): array
{
    preg_match_all('/fi-sc-wizard-header-step-label[^>]*>\s*([^<]+?)\s*</', $html, $matches);

    return array_map('trim', $matches[1] ?? []);
}

test('owner with a company is first asked whether to use an existing one', function () {
    $owner = wizardOwner();
    Company::factory()->forUser($owner)->create();

    $component = Livewire::test(CreateFishery::class);
    $html = $component->html();

    expect($component->get('data.company_mode'))->toBe('existing');
    expect($html)->toContain(__('Create a fishery for one of my companies'));
    expect($html)->toContain(__('Choose a company'));
    // Formularz nowej firmy to osobny krok i jest na razie pominięty.
    expect(wizardStepLabels($html))->not->toContain(__('Company data'));
});

test('owner without any company skips the question and starts at the company form', function () {
    wizardOwner();

    $html = Livewire::test(CreateFishery::class)->html();

    // Nie ma o co pytać — krok z wyborem znika w całości, a kreator zaczyna
    // się od formularza firmy, wciąż pokazując pozostałe kroki w nagłówku.
    expect(wizardStepLabels($html))->toBe([
        __('Company data'),
        __('Verification transfer'),
        __('Fishery'),
    ]);
    expect($html)->not->toContain(__('Create a fishery for one of my companies'));
    expect($html)->toContain(__('Get data from CSO'));
});

test('choosing to create a new company inserts the company form step', function () {
    $owner = wizardOwner();
    Company::factory()->forUser($owner)->create(['is_verified' => true]);

    $component = Livewire::test(CreateFishery::class)
        ->fillForm(['company_mode' => 'new']);

    expect(wizardStepLabels($component->html()))->toBe([
        __('Company'),
        __('Company data'),
        __('Verification transfer'),
        __('Fishery'),
    ]);
    expect($component->html())->toContain(__('Company name'));
});

/**
 * Reguła produktowa: przelew weryfikacyjny dotyczy WYŁĄCZNIE nowo zakładanej firmy.
 * Firma wybrana z listy jest z założenia zweryfikowana, więc krok nie ma prawa się
 * pojawić — również wtedy, gdy w bazie ma `is_verified = false`. To celowo NIE jest
 * warunek na danych (patrz komentarz w `CreateFishery::verificationStep()`).
 */
test('verification step never appears for a company chosen from the list', function () {
    $owner = wizardOwner();

    foreach ([true, false] as $isVerified) {
        $company = Company::factory()->forUser($owner)->create(['is_verified' => $isVerified]);

        $html = Livewire::test(CreateFishery::class)
            ->fillForm(['company_mode' => 'existing', 'company_id' => $company->id])
            ->html();

        expect($html)->not->toContain(__('Transfer for 1 złoty is required to verify company.'));
        expect(wizardStepLabels($html))->toBe(
            [__('Company'), __('Fishery')],
            'Firma z listy (is_verified='.var_export($isVerified, true).') nie powinna wymagać weryfikacji.'
        );
    }
});

test('verification step always appears when a new company is being created', function () {
    $owner = wizardOwner();
    Company::factory()->forUser($owner)->create(['is_verified' => true]);

    $html = Livewire::test(CreateFishery::class)
        ->fillForm(['company_mode' => 'new'])
        ->html();

    expect($html)->toContain(__('Transfer for 1 złoty is required to verify company.'));
    expect(wizardStepLabels($html))->toBe([
        __('Company'),
        __('Company data'),
        __('Verification transfer'),
        __('Fishery'),
    ]);
});

/**
 * Regresja: `Placeholder::make('cso_error_message')->label('')` NIE ukrywał
 * etykiety — Filament traktuje pusty ciąg jak brak ustawienia i wypisywał
 * „Cso error message" na czystym formularzu firmy.
 */
test('the CSO error placeholder stays invisible while there is no error', function () {
    wizardOwner();

    $html = Livewire::test(CreateFishery::class)->html();

    expect($html)->toContain(__('Get data from CSO'));
    expect($html)->not->toContain('Cso error message');
});

test('creating a fishery through the wizard assigns the chosen existing company', function () {
    $owner = wizardOwner();
    $company = Company::factory()->forUser($owner)->create(['is_verified' => true]);
    $state = State::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'existing',
            'company_id' => $company->id,
            ...wizardFisheryData($state),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fishery = Fishery::latest('id')->first();

    expect($fishery)->not->toBeNull();
    expect($fishery->company_id)->toBe($company->id);
    expect($fishery->user_id)->toBe($owner->id);
});

/**
 * Kreator kończy proces zakładania łowiska, więc właściciel ląduje od razu na
 * jego stronie zarządzania (stanowiska, pozwolenia, usługi) — a nie na liście,
 * jak zwykły CRUD z zadania 011.
 */
test('finishing the wizard lands the owner on the manage page of the new fishery', function () {
    $owner = wizardOwner();
    $company = Company::factory()->forUser($owner)->create(['is_verified' => true]);
    $state = State::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'existing',
            'company_id' => $company->id,
            ...wizardFisheryData($state),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(
            FisheryResource::getUrl('manage', ['record' => Fishery::latest('id')->first()])
        );
});

test('creating a fishery through the wizard also creates the new company and links it', function () {
    $owner = wizardOwner();
    $state = State::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'new',
            'company' => [
                'name' => 'Nowa Firma',
                'street' => 'Polna',
                'house_number' => '5',
                'postal_code' => '11-111',
                'city' => 'Kraków',
                'state_id' => $state->id,
            ],
            ...wizardFisheryData($state),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fishery = Fishery::latest('id')->first();
    $company = Company::latest('id')->first();

    expect($company)->not->toBeNull();
    expect($company->name)->toBe('Nowa Firma');
    // Firma założona w kreatorze musi należeć do właściciela, nie zawisnąć bez
    // przypisania — inaczej zniknęłaby z jego listy firm.
    expect($company->user_id)->toBe($owner->id);
    expect($fishery->company_id)->toBe($company->id);
});

/**
 * Ścieżka „mam firmy, ale zakładam kolejną" — najłatwiejsza do zepsucia, bo
 * `company_id` istniejącej firmy może zostać w stanie formularza i przykryć
 * świeżo utworzoną firmę.
 */
test('owner who already has companies can still create a fishery for a brand new one', function () {
    $owner = wizardOwner();
    $existing = Company::factory()->forUser($owner)->create(['name' => 'Stara Firma']);
    $state = State::factory()->create();

    Livewire::test(CreateFishery::class)
        ->fillForm([
            'company_mode' => 'new',
            'company' => [
                'name' => 'Druga Firma',
                'street' => 'Leśna',
                'house_number' => '7',
                'postal_code' => '22-222',
                'city' => 'Gdańsk',
                'state_id' => $state->id,
            ],
            ...wizardFisheryData($state),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fishery = Fishery::latest('id')->first();

    expect(Company::query()->where('user_id', $owner->id)->count())->toBe(2);
    expect($fishery->company_id)->not->toBe($existing->id);
    expect(Company::find($fishery->company_id)->name)->toBe('Druga Firma');
});

test('creating a company outside the wizard stays a plain form, without wizard steps', function () {
    wizardOwner();

    test()->get('/owner/companies/create')
        ->assertStatus(200)
        ->assertDontSee(__('Verification transfer'))
        ->assertDontSee(__('Create fishery wizard'));
});

test('admin panel keeps the flat fishery form instead of the owner wizard', function () {
    $admin = test()->createSuperAdmin();
    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $component = Livewire::test(CreateFishery::class);

    // Płaski formularz ma pole firmy od razu w stanie i nie zna trybu kreatora.
    expect($component->get('data'))->toHaveKey('company_id');
    expect((array) $component->get('data'))->not->toHaveKey('company_mode');
    expect($component->html())->not->toContain(__('Verification transfer'));
});
