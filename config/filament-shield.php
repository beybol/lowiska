<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

/*
|--------------------------------------------------------------------------
| Migracja schematu Shielda 3.x → 4.x (zadanie 009)
|--------------------------------------------------------------------------
|
| Shield 4 przepisał cały plik konfiguracyjny. Równoważności względem 3.x:
|   - `auth_provider_model.fqcn` (tablica) → `auth_provider_model` (string FQCN)
|   - `permission_prefixes` → `permissions` (budowniczy kluczy: separator + case)
|   - `entities` → `shield_resource.tabs`
|   - `generator.option` → `policies.generate` + `permissions.generate`
|   - `exclude.pages|widgets` (nazwy) → `pages.exclude` / `widgets.exclude` (klasy)
|   - `register_role_policy.enabled` (tablica) → `register_role_policy` (bool)
|
| `super_admin.name` PRZETRWAŁO bez zmian — zadanie 008 (MakeAdminCommand
| i tests/TestCase.php) czyta `config('filament-shield.super_admin.name')`
| i nadal dostaje `super_admin`.
|
*/

return [

    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => true,
            'widgets' => true,
            'resources' => true,
            'custom_permissions' => false,
        ],
    ],

    'tenant_model' => null,

    'auth_provider_model' => 'App\\Models\\User',

    /*
    | define_via_gate zostaje `false` — super admin ma mieć FIZYCZNIE przypisane
    | uprawnienia, nie obejście przez Gate::before(). Uzasadnienie w zadaniu 008
    | i docs/conventions/autoryzacja.md; upgrade tego nie zmienia.
    */
    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    'panel_user' => [
        'enabled' => true,
        'name' => 'panel_user',
    ],

    /*
    | ⚠️ Format kluczy uprawnień ZMIENIŁ SIĘ wraz z Shieldem 4 i nie da się
    | odtworzyć formatu 3.x (`view_any_additional::service`) — separator `_`
    | jest zabroniony przy case'ach snake, a człon modelu nie używa już `::`.
    | Wybrano `lower_snake` + `:`, bo daje `view_any:additional_service`:
    | najbliżej starego zapisu, więc zmiana w politykach jest jednoznakowa
    | i czytelna w przeglądzie, zamiast pełnej zmiany wielkości liter
    | (domyślne `pascal` dawałoby `ViewAny:AdditionalService`).
    */
    'permissions' => [
        'separator' => ':',
        'case' => 'lower_snake',
        'generate' => true,
        'format_custom_permission_keys' => true,
    ],

    /*
    | ⚠️ `generate => false` jest CELOWE i nie wolno go włączyć bez namysłu.
    | Projekt utrzymuje czternaście polityk w app/Policies/ ręcznie — niosą
    | logikę widoczności danych właściciela, której generator Shielda nie zna
    | i którą nadpisałby zaślepkami. Shield ma tu generować WYŁĄCZNIE
    | uprawnienia (patrz `permissions.generate` wyżej).
    */
    'policies' => [
        'path' => app_path('Policies'),
        'merge' => true,
        'generate' => false,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
            'forceDeleteAny',
            'restoreAny',
            'reorder',
        ],
    ],

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],
        ],
        'exclude' => [
            //
        ],
    ],

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    'custom_permissions' => [],

    /*
    | Aplikacja ma DWA panele (`admin`, `owner`). Odkrywanie ograniczone do
    | panelu domyślnego pominęłoby stronę VerifyCompany panelu właściciela —
    | dokładnie ten objaw (administrator bez dostępu) naprawiało zadanie 008.
    | Uprawnienie nieprzypisane do żadnej roli jest bezczynne, więc nadmiar
    | nic nie kosztuje; brak kosztuje niewidoczność.
    */
    'discovery' => [
        'discover_all_resources' => true,
        'discover_all_widgets' => true,
        'discover_all_pages' => true,
    ],

    'register_role_policy' => true,

];
