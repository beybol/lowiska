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
     * Deklaruje język klienta testowego (zadanie 009).
     *
     * `phpunit.xml` wymusza `APP_LOCALE=pl` (zadanie 006), ale od
     * filament-language-switch 5.x o locale ŻĄDANIA decyduje middleware
     * `SwitchLanguageLocale`, który czyta m.in. nagłówek `Accept-Language`.
     * Klient testowy Symfony wysyła domyślnie `en-us,en;q=0.5`, więc panele
     * renderowały się po angielsku wbrew wymuszeniu z `phpunit.xml` — a wtedy
     * `assertDontSee(__('Fish'))` pęka, bo „Fish" jest podciągiem „Fisheries"
     * (po polsku „Ryby" i „Łowiska" nie kolidują).
     *
     * ⚠️ To nie jest obejście testu: aplikacja ma honorować `Accept-Language`
     * i w przeglądarce robi to poprawnie. Brakowało wyłącznie tego, żeby sam
     * pakiet testów powiedział, w jakim języku rozmawia.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Accept-Language', 'pl');
    }

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
        'view_any:company',
        'view:company',
        'create:company',
        'update:company',
        'delete:company',
    ];

    private array $fisheryPermissions = [
        'view_any:fishery',
        'view:fishery',
        'create:fishery',
        'update:fishery',
        'delete:fishery',
    ];

    private array $conveniencePermissions = [
        'view_any:convenience',
        'view:convenience',
        'create:convenience',
        'update:convenience',
        'delete:convenience',
    ];

    private array $countryPermissions = [
        'view_any:country',
        'view:country',
        'create:country',
        'update:country',
        'delete:country',
    ];

    private array $fishPermissions = [
        'view_any:fish',
        'view:fish',
        'create:fish',
        'update:fish',
        'delete:fish',
    ];

    private array $fisheryTypePermissions = [
        'view_any:fishery_type',
        'view:fishery_type',
        'create:fishery_type',
        'update:fishery_type',
        'delete:fishery_type',
    ];

    private array $fishingMethodPermissions = [
        'view_any:fishing_method',
        'view:fishing_method',
        'create:fishing_method',
        'update:fishing_method',
        'delete:fishing_method',
    ];

    private array $statePermissions = [
        'view_any:state',
        'view:state',
        'create:state',
        'update:state',
        'delete:state',
    ];

    private array $userPermissions = [
        'view_any:user',
        'view:user',
        'create:user',
        'update:user',
        'delete:user',
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
