<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Schemat, w którym wolno uruchamiać pakiet testów.
     */
    private const TEST_DATABASE = 'lowiska_test';

    /**
     * Trzecia z pięciu warstw izolacji pakietu testów (ADR-001).
     *
     * Sprawdza ROZWIĄZANE połączenie, nie same zmienne środowiskowe — dopiero
     * po zbudowaniu aplikacji widać, w którą bazę naprawdę pójdą zapytania.
     *
     * Niezgodność przerywa CAŁY pakiet przez exit(1): nieudana asercja
     * przerwałaby tylko jeden test i wpuściła następny na złą bazę, a
     * RefreshDatabase czyści tę bazę, do której akurat wskazuje połączenie.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $environment = $app->environment();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($environment !== 'testing' || $database !== self::TEST_DATABASE) {
            fwrite(STDERR, PHP_EOL.implode(PHP_EOL, [
                '╔════════════════════════════════════════════════════════════════════╗',
                '║  PAKIET TESTÓW ZATRZYMANY — połączenie wskazuje niewłaściwą bazę.  ║',
                '╚════════════════════════════════════════════════════════════════════╝',
                '',
                "  Środowisko:  {$environment}  (wymagane: testing)",
                "  Połączenie:  {$connection}",
                "  Baza:        {$database}  (wymagana: ".self::TEST_DATABASE.')',
                '',
                '  Uruchomienie testów na tej bazie skasowałoby jej zawartość',
                '  (RefreshDatabase). Sprawdź, czy zmienne DB_* ze środowiska nie',
                '  przesłaniają deklaracji z phpunit.xml — patrz docs/operations/docker.md.',
                '',
            ]).PHP_EOL);

            exit(1);
        }

        return $app;
    }

    private array $companyPermissions = [
        'view_any_company',
        'view_company',
        'create_company',
        'update_company',
        'delete_company',
    ];

    private array $fisheryPermissions = [
        'view_any_fishery',
        'view_fishery',
        'create_fishery',
        'update_fishery',
        'delete_fishery',
    ];

    private array $conveniencePermissions = [
        'view_any_convenience',
        'view_convenience',
        'create_convenience',
        'update_convenience',
        'delete_convenience',
    ];

    private array $countryPermissions = [
        'view_any_country',
        'view_country',
        'create_country',
        'update_country',
        'delete_country',
    ];

    private array $fishPermissions = [
        'view_any_fish',
        'view_fish',
        'create_fish',
        'update_fish',
        'delete_fish',
    ];

    private array $fisheryTypePermissions = [
        'view_any_fishery::type',
        'view_fishery::type',
        'create_fishery::type',
        'update_fishery::type',
        'delete_fishery::type',
    ];

    private array $fishingMethodPermissions = [
        'view_any_fishing::method',
        'view_fishing::method',
        'create_fishing::method',
        'update_fishing::method',
        'delete_fishing::method',
    ];

    private array $statePermissions = [
        'view_any_state',
        'view_state',
        'create_state',
        'update_state',
        'delete_state',
    ];

    private array $userPermissions = [
        'view_any_user',
        'view_user',
        'create_user',
        'update_user',
        'delete_user',
    ];

    protected function createSuperAdmin(array $attributes = []): User
    {
        $superAdminRole = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name')]);
        $requiredPermissions = array_merge(
            $this->companyPermissions,
            $this->fisheryPermissions,
            $this->conveniencePermissions,
            $this->countryPermissions,
            $this->fishPermissions,
            $this->fisheryTypePermissions,
            $this->fishingMethodPermissions,
            $this->statePermissions,
            $this->userPermissions,
        );

        foreach ($requiredPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $allPermissions = Permission::all();
        $superAdminRole->syncPermissions($allPermissions);
        $defaultAttributes = ['is_admin' => 1];
        $admin = User::factory()->create(
            array_merge($defaultAttributes, $attributes),
        );
        $admin->assignRole($superAdminRole);

        return $admin;
    }
}
