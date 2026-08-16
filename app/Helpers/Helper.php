<?php

namespace App\Helpers;

use App\Filament\Resources\FisheryResource;
use App\Models\Company;
use App\Models\Country;
use App\Models\Fishery;
use App\Models\State;
use App\Models\User;
use App\Services\CSOService;
use Collator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Helper
{
    /**
     * Klucz pamięci podręcznej `findFishery()` w kontenerze.
     *
     * ⚠️ Celowo w kontenerze, a nie we `właściwości static` — kontener jest odtwarzany
     * na każde żądanie **i na każdy test**, a statyczna tablica przeżywałaby
     * `RefreshDatabase` i podawała kolejnemu testowi model z wyczyszczonej tabeli.
     */
    private const FISHERY_CACHE = 'lowiska.fishery_cache';

    public static function syncAdditionalServices($record, array $services): void
    {
        $record->additionalServices()->sync(
            collect($services)
                ->mapWithKeys(function ($item) {
                    return [
                        $item['additional_service_id'] => [
                            'is_required' => $item['is_required'] ?? false,
                        ],
                    ];
                })
                ->toArray()
        );
    }

    public static function extractAdditionalServices(array &$data): array
    {
        $services = $data['additionalServices'] ?? [];
        unset($data['additionalServices']);

        return $services;
    }

    public static function getFisheryTitle(?int $fisheryId, string $baseLabel): string
    {
        if ($fisheryId) {
            // ⚠️ Przez `findFishery()`, nie gołym `Fishery::find()` — tytuł strony
            // ujawniał nazwę cudzego łowiska tak samo jak okruszki (audyt, zadanie 012).
            $fishery = self::findFishery($fisheryId);
            if ($fishery) {
                return __($baseLabel.' for fishery').' '.$fishery->name;
            }
        }

        return __($baseLabel);
    }

    /**
     * Okruszki dla zasobów podrzędnych łowiska:
     * `Łowiska > {nazwa łowiska} > {sekcja} [> {bieżąca strona}]`.
     *
     * „Łowiska" prowadzi do listy łowisk, nazwa łowiska — do jego strony
     * zarządzania, więc z każdego ekranu podrzędnego da się wrócić o poziom wyżej
     * bez cofania w przeglądarce (zadanie 012).
     *
     * Gdy `$fisheryId` jest puste (zasób otwarty bez kontekstu łowiska, np.
     * z panelu administratora), człon z nazwą łowiska po prostu znika.
     *
     * @param  string|null  $sectionUrl  gdy podany, sekcja staje się klikalna,
     *                                   a `$currentLabel` dokłada się jako ostatni,
     *                                   nieklikalny człon
     * @return array<int|string, string>
     */
    public static function fisheryBreadcrumbs(
        int|string|null $fisheryId,
        string $sectionLabel,
        ?string $sectionUrl = null,
        ?string $currentLabel = null,
    ): array {
        $breadcrumbs = [
            FisheryResource::getUrl('index') => __('Fisheries'),
        ];

        $fishery = self::findFishery($fisheryId);

        if ($fishery) {
            $breadcrumbs[FisheryResource::getUrl('manage', ['record' => $fishery])] = $fishery->name;
        }

        if (filled($currentLabel) && filled($sectionUrl)) {
            $breadcrumbs[$sectionUrl] = $sectionLabel;
            $breadcrumbs[] = $currentLabel;

            return $breadcrumbs;
        }

        $breadcrumbs[] = $sectionLabel;

        return $breadcrumbs;
    }

    public static function getListHeaderActionsForFishery($resourceClass, $fisheryId)
    {
        $actions = [];

        if ($fisheryId) {
            $actions[] = CreateAction::make()
                ->url(fn () => $resourceClass::getUrl('create', ['fishery' => $fisheryId]));
            $actions[] = self::getBackToFisheryManagementAction($fisheryId, 'action');
        } else {
            $actions[] = CreateAction::make();
        }

        return $actions;
    }

    /**
     * Adres konkretnej zakładki huba „Zarządzaj łowiskiem".
     *
     * Filament identyfikuje aktywny RelationManager **pozycją w tablicy**
     * `FisheryResource::getRelations()` (parametr `?relation=`), a nie nazwą klasy.
     * ⚠️ Dlatego klucza nie wolno zaszywać liczbą w stronach — przestawienie kolejności
     * zakładek przekierowywałoby po zapisie na cudzą listę i nic by nie pękło. Tu jest
     * wyliczany z tej samej tablicy, którą renderuje hub (zadanie 012).
     *
     * @param  class-string  $relationManager
     */
    public static function fisheryHubUrl(int|string|null $fisheryId, string $relationManager): ?string
    {
        $fishery = self::findFishery($fisheryId);

        if (! $fishery) {
            return null;
        }

        $relation = array_search($relationManager, FisheryResource::getRelations(), true);

        return FisheryResource::getUrl('manage', array_filter([
            'record' => $fishery,
            'relation' => $relation === false ? null : $relation,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Adres listy zasobu podrzędnego: zakładka huba, a gdy łowiska nie da się ustalić —
     * samodzielna strona listy jako fallback.
     *
     * Jedna implementacja dla wszystkich sześciu stron Create/Edit zasobów podrzędnych.
     * ⚠️ Wcześniej ta sama metoda była skopiowana sześć razy jako prywatna `sectionUrl()`,
     * więc zmiana reguły fallbacku wymagała edycji sześciu plików.
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $relationManager
     */
    public static function fisherySectionUrl(
        string $resourceClass,
        string $relationManager,
        int|string|null $fisheryId,
    ): string {
        return self::fisheryHubUrl($fisheryId, $relationManager)
            ?? $resourceClass::getUrl('index', ['fishery' => $fisheryId]);
    }

    /**
     * Łowisko po ID, z memoizacją w obrębie żądania.
     *
     * ⚠️ Renderowanie strony podrzędnej sięga po ten sam wiersz cztery razy (bramka
     * dostępu, adres sekcji, okruszki, hydratacja pola `fishery_id`), a pola adresu
     * w kreatorze są `live()`, więc powtarza się to przy każdym renderze Livewire.
     */
    private static function findFishery(
        int|string|null $fisheryId,
        ?bool $scopedToCurrentUser = null,
    ): ?Fishery {
        // ⚠️ Domyślnie ZAWĘŻONE. Wariant nieograniczony trzeba wybrać świadomie.
        // Powód: okruszki i tytuły stron `Create*` czytają `?fishery` wprost z żądania
        // (bramka z `mount()` nie biegnie przy kolejnych żądaniach Livewire), więc przy
        // domyślnie szerokim wyszukiwaniu `POST /livewire/update?fishery=<cudze>` zwracał
        // NAZWĘ cudzego łowiska i link do jego huba — jedyna ścieżka odczytu omijająca
        // wszystkie trzy warstwy z `docs/conventions/autoryzacja.md` §4.
        $scopedToCurrentUser ??= ! self::isAdminPanel();

        if (! is_numeric($fisheryId)) {
            return null;
        }

        $fisheryId = (int) $fisheryId;

        // Wariant zawężony do użytkownika trzyma się pod osobnym kluczem — te dwa
        // nie mogą się nawzajem podmieniać, bo drugi jest bramką dostępu.
        // ⚠️ W kluczu jest też ID użytkownika: kontener przeżywa WIELE żądań HTTP
        // w obrębie jednego testu (aplikacja wstaje w `setUp()`, nie przy każdym
        // `$this->get()`), więc test wchodzący najpierw jako A, potem jako B na to
        // samo łowisko dostałby z cache'u wpis A i przeszedłby na zielono mimo
        // zepsutej bramki.
        $cacheKey = $fisheryId.($scopedToCurrentUser ? ':own:'.auth()->id() : '');

        /** @var \ArrayObject<string, Fishery|null> $cache */
        $cache = app()->bound(self::FISHERY_CACHE)
            ? app(self::FISHERY_CACHE)
            : tap(new \ArrayObject, fn (\ArrayObject $fresh) => app()->instance(self::FISHERY_CACHE, $fresh));

        if ($cache->offsetExists($cacheKey)) {
            return $cache->offsetGet($cacheKey);
        }

        $query = Fishery::query();

        if ($scopedToCurrentUser) {
            $query->forCurrentUser();
        }

        $cache->offsetSet($cacheKey, $fishery = $query->find($fisheryId));

        return $fishery;
    }

    public static function getEditFormActionsForFishery($record, $saveAction, $cancelAction)
    {
        $cancelActionModifier = self::getBackToFisheryManagementAction(
            $record->fishery_id ?? null,
            'cancel',
        );

        return [
            $saveAction,
            $cancelActionModifier($cancelAction),
        ];
    }

    /**
     * Bramka dostępu do łowiska; **zwraca zweryfikowane ID**.
     *
     * ⚠️ Zwracaną wartość trzeba zapamiętać po stronie serwera i to JEJ używać przy
     * zapisie. `fishery_id` w formularzu jest polem `Hidden`, czyli danymi od klienta —
     * bramka w `mount()` sprawdza parametr `?fishery`, ale nic nie pilnowało, że
     * zapisywany rekord trafia do tego samego łowiska. Polityki zasobów podrzędnych
     * tego nie wyłapią, bo przy tworzeniu nie widzą rekordu nadrzędnego.
     */
    public static function assertFisheryAccessOrAbort(
        int|string|null $fisheryId = null,
    ): int {
        $fisheryId = $fisheryId ?? request()->get('fishery');

        if (! is_numeric($fisheryId)) {
            abort(404);
        }

        $fisheryId = (int) $fisheryId;

        $fishery = self::findFishery($fisheryId);

        if (! $fishery) {
            abort(404);
        }

        return $fisheryId;
    }

    /**
     * Zawęża zapytanie zasobu **podrzędnego wobec łowiska** do łowisk właściciela.
     *
     * ⚠️ Jedno miejsce dla całego niezmiennika widoczności tych zasobów. Wcześniej ta
     * sama reguła była przeklejona do `getEloquentQuery()` trzech zasobów — czwarty
     * zasób podrzędny dodany za pół roku po prostu by jej nie dostał i nic by nie pękło.
     * Reguła i jej trzy warstwy: `docs/conventions/autoryzacja.md` §4.
     *
     * Podzapytanie zamiast `whereHas` z domknięciem: Larastan nie rozwiązuje typu
     * w domknięciu `whereHas` (widzi `Builder<Model>`), więc scope `forCurrentUser()`
     * zgłaszałby się jako nieistniejąca metoda.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function scopeToOwnedFisheries(Builder $query): void
    {
        // ⚠️ Warunek jest fail-closed (`! isAdminPanel()`), a nie `isOwnerPanel()` —
        // tak samo jak bramka `assertFisheryAccessOrAbort()`. Obie połowy tego samego
        // niezmiennika muszą reagować identycznie na nierozpoznany kontekst panelu:
        // przy `isOwnerPanel()` nieznany panel oznaczałby BRAK zawężenia.
        if (self::isAdminPanel()) {
            return;
        }

        $query->whereIn(
            'fishery_id',
            Fishery::query()->forCurrentUser()->select('id'),
        );
    }

    private static function isAdminPanel(): bool
    {
        return Filament::getCurrentOrDefaultPanel()?->getId() === 'admin';
    }

    /**
     * Autoryzuje łowisko zgłoszone w danych formularza i wpisuje je z powrotem.
     *
     * ⚠️ Jedyne miejsce, które decyduje, do jakiego łowiska trafia rekord podrzędny.
     * Polityki zasobów podrzędnych tego nie wyłapią — przy tworzeniu nie widzą rekordu
     * nadrzędnego, więc `PositionPolicy::create()` przepuszcza każdego właściciela.
     *
     * ⚠️ Bramka MUSI działać na wartości ze **zgłoszenia**, a nie na ID zapamiętanym
     * w `mount()`: Livewire utrwala między żądaniami wyłącznie właściwości publiczne,
     * a żądanie zapisu leci na `/livewire/update`, więc nie niesie ani `?fishery`,
     * ani niczego z `protected`. Zapamiętane ID było tam po prostu `null`.
     * Ta wersja jest bezstanowa: cokolwiek przyjdzie od klienta, musi przejść przez
     * `assertFisheryAccessOrAbort()`, które poza panelem admina zawęża do łowisk
     * bieżącego użytkownika.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forceVerifiedFishery(array $data): array
    {
        $data['fishery_id'] = self::assertFisheryAccessOrAbort(
            $data['fishery_id'] ?? request()->get('fishery'),
        );

        return $data;
    }

    public static function getRichEditorOptions()
    {
        return [
            'bold',
            'bulletList',
            'italic',
            'orderedList',
            'underline',
        ];
    }

    public static function setAddress(Set $set, array $address): void
    {
        if (isset($address['error'])) {
            $set('error', $address['error']);
        } else {
            $set('cso_response', $address['cso_response'] ?? null);
            $name = $address['name'] ?? null;
            $set('name', $name);
            $renae = $address['renae'] ?? null;
            $set('renae', $renae);
            $tin = $address['tin'] ?? null;
            $set('tin', $tin);
            $street = $address['street'] ?? null;
            $set('street', $street);
            $hN = $address['house_number'] ?? null;
            $set('house_number', $hN);
            $fN = $address['flat_number'] ?? null;
            $set('flat_number', $fN);
            $postalCode = $address['postal_code'] ?? null;
            $set('postal_code', $postalCode);
            $city = $address['city'] ?? null;
            $set('city', $city);
            $stateId = $address['state_id'] ?? null;
            $set('state_id', $stateId);
            $set('error', null);
        }
    }

    public static function getSortedCountries(): Collection
    {
        $collator = new Collator(app()->getLocale());

        return Country::active()
            ->get()
            ->sort(function ($country1, $country2) use ($collator) {
                return $collator->compare(
                    __($country1->country_name),
                    __($country2->country_name)
                );
            });
    }

    public static function getCountryPrefixes(): array
    {
        return self::getSortedCountries()
            ->mapWithKeys(function ($country) {
                $label = __($country->country_name).", {$country->prefix}";

                return [$country->id => $label];
            })
            ->toArray();
    }

    public static function getSocialAuthActions(
        string $actionType = 'login'
    ): array {
        $currentPanel = Filament::getCurrentOrDefaultPanel();
        $panelId = $currentPanel ? $currentPanel->getId() : 'admin';

        return [
            Action::make($actionType.'_google')
                ->label(__(
                    $actionType === 'login'
                        ? 'Login with Google'
                        : 'Register with Google'
                ))
                ->color('gray')
                ->icon(fn () => view('components.icons.google'))
                ->url(route('social.redirect', [
                    'provider' => 'google',
                    'source' => $panelId,
                ])),
            Action::make($actionType.'_facebook')
                ->label(__(
                    $actionType === 'login'
                        ? 'Login with Facebook'
                        : 'Register with Facebook'
                ))
                ->color('gray')
                ->icon(fn () => view('components.icons.facebook'))
                ->url(route('social.redirect', [
                    'provider' => 'facebook',
                    'source' => $panelId,
                ])),
        ];
    }

    public static function isOwnerPanel(): bool
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $panel?->getId() === 'owner';
    }

    public static function sortStates()
    {
        $collator = new Collator(app()->getLocale());

        return State::all()
            ->sort(function ($state1, $state2) use ($collator) {
                return $collator->compare(
                    __($state1->name),
                    __($state2->name)
                );
            })
            ->pluck('name', 'id')
            ->map(fn ($name) => __($name));
    }

    public static function sortedCompanies($query = null, $modelsOnly = false)
    {
        $collator = new Collator(app()->getLocale());
        $companies = $query ?? Company::query();

        $sortedCompanies = $companies->get()
            ->sort(function ($company1, $company2) use ($collator) {
                return $collator->compare($company1->name, $company2->name);
            });

        return $modelsOnly
            ? $sortedCompanies
            : $sortedCompanies->pluck('name', 'id');
    }

    public static function addOwnerRole(User $user)
    {
        $companyPermissions = [
            'view_any:company',
            'view:company',
            'create:company',
            'update:company',
            'delete:company',
            'delete_any:company',
            'force_delete:company',
            'force_delete_any:company',
            'restore:company',
            'restore_any:company',
            'replicate:company',
            'reorder:company',
        ];

        foreach ($companyPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $fisheryPermissions = [
            'view_any:fishery',
            'view:fishery',
            'create:fishery',
            'update:fishery',
            'delete:fishery',
            'delete_any:fishery',
            'force_delete:fishery',
            'force_delete_any:fishery',
            'restore:fishery',
            'restore_any:fishery',
            'replicate:fishery',
            'reorder:fishery',
        ];

        foreach ($fisheryPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $role = Role::firstOrCreate(['name' => 'owner']);
        $role->syncPermissions(array_merge($companyPermissions, $fisheryPermissions));
        $user->assignRole($role);
    }

    public static function fetchDataFromCSO(
        Get $get,
        Set $set,
        ?Company $record
    ): void {
        $tin = trim($get('tin'));
        $renae = trim($get('renae'));

        if (! $tin && ! $renae) {
            $set(
                'error',
                __('Please provide TIN or RENAE number to fetch data from CSO.'),
            );

            return;
        }

        if ($tin && ! CSOService::isValidTIN($tin)) {
            if ($renae && ! CSOService::isValidRENAE($renae)) {
                $set('error', __('Invalid TIN and RENAE format.'));

                return;
            }

            $set('error', __('Invalid TIN format.'));

            return;
        }

        if ($renae && ! CSOService::isValidRENAE($renae)) {
            $set('error', __('Invalid RENAE format.'));

            return;
        }

        $company = Company::query()->findByNumber($tin, $renae)->first();

        if ($company) {
            if (! $record || $company->id !== $record->id) {
                $currentUser = Filament::auth()->user();
                $companyUser = $company->user;

                if ($companyUser->is($currentUser)) {
                    $set(
                        'error',
                        __('You have already entered the company.'),
                    );
                } else {
                    $adminEmail = config('app.admin_email');
                    $errorMessage = __('The company provided has already been entered by another person. Please contact the administrator to clarify the situation');
                    $set('error', "$errorMessage - $adminEmail.");
                }

                return;
            }
        }

        $set('error', null);

        if ($tin && $renae) {
            if (CSOService::checkAreMatched($tin, $renae)) {
                $address = CSOService::fetchAddress($tin, true);
                Helper::setAddress($set, $address);
            } else {
                $set(
                    'error',
                    __('TIN and RENAE do not match. Please check numbers and try again.'),
                );
            }
        } else {
            if ($tin) {
                $address = CSOService::fetchAddress($tin, true);
                Helper::setAddress($set, $address);
            } elseif ($renae) {
                $address = CSOService::fetchAddress($renae);
                Helper::setAddress($set, $address);
            }
        }
    }

    public static function getPriceInput()
    {
        return TextInput::make('price')
            ->label(__('Price'))
            ->inputMode('decimal')
            ->placeholder('100.00')
            ->rules([
                'nullable',
                'regex:/^\d+([.,]\d{1,2})?$/',
                // ⚠️ Reguła-domknięcie musi być OPAKOWANA w domknięcie, które ją
                // zwraca. Filament woła `evaluate()` na każdym elemencie `rules()`
                // i wstrzykuje argumenty PO NAZWIE — przekazana wprost reguła
                // Laravela wywala się na `[$attribute] was unresolvable`
                // (BindingResolutionException) dopiero przy zapisie formularza.
                static fn (): \Closure => static function (string $attribute, $value, \Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    if ((float) str_replace(',', '.', $value) < 0.01) {
                        $fail(__('The price must be at least 0.01.'));
                    }
                },
            ])
            ->dehydrateStateUsing(fn (?string $state) => filled($state)
                ? (float) str_replace(',', '.', $state)
                : null)
            ->default(null);
    }

    public static function getFisheryFields()
    {
        return [
            TextInput::make('fishery_name')
                ->label(__('Fishery'))
                ->afterStateHydrated(function ($component, $state, $record) {
                    if ($record && $record->fishery) {
                        $component->state($record->fishery->name);
                    } elseif ($fisheryId = request()->get('fishery')) {
                        $component->state(self::findFishery($fisheryId)?->name);
                    }
                })
                ->readonly(),
            // ⚠️ To pole jest wyłącznie WYGODĄ formularza, nie źródłem prawdy.
            // Wartość zapisywana do bazy wymusza `Helper::forceVerifiedFishery()`
            // ze zweryfikowanego ID zapamiętanego w `mount()` — `Hidden` to dane
            // od klienta i bez tego dało się utworzyć rekord pod cudzym łowiskiem.
            Hidden::make('fishery_id')
                ->default(function ($record, $livewire = null) {
                    if ($record && $record->fishery_id) {
                        return $record->fishery_id;
                    }

                    $fisheryId = request()->get('fishery');
                    if (! $fisheryId && $livewire && property_exists($livewire, 'record') && $livewire->record && $livewire->record->fishery_id) {
                        $fisheryId = $livewire->record->fishery_id;
                    }

                    return $fisheryId;
                })
                ->required(),
        ];
    }

    public static function getBackToFisheryManagementAction($fisheryId = null, $actionType = 'cancel')
    {
        $url = function () use ($fisheryId) {
            $id = $fisheryId;

            if (! $id) {
                $id = request()->get('fishery');
            }

            if (! $id && isset($this->record)) {
                $id = $this->record->fishery_id;
            }

            if ($id) {
                return FisheryResource::getUrl('manage', ['record' => $id]);
            }

            return back();
        };

        if ($actionType === 'cancel') {
            return function ($cancelAction) use ($url) {
                return $cancelAction
                    ->label(__('Back to fishery management'))
                    ->url($url);
            };
        } else {
            return Action::make('back_to_fishery')
                ->label(__('Back to fishery management'))
                ->url($url)
                ->color('gray');
        }
    }

    public static function getFisheryManagementEditFormActions(): array
    {
        $cancelActionModifier = Helper::getBackToFisheryManagementAction(
            $this->record->fishery_id,
            'cancel',
        );

        return [
            $this->getSaveFormAction(),
            $cancelActionModifier($this->getCancelFormAction()),
        ];
    }
}
