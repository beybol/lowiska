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
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Helper
{
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
            $fishery = Fishery::find($fisheryId);
            if ($fishery) {
                return __($baseLabel.' for fishery').' '.$fishery->name;
            }
        }

        return __($baseLabel);
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

    public static function assertFisheryAccessOrAbort(
        ?int $fisheryId = null,
    ): void {
        $fisheryId = $fisheryId ?? request()->get('fishery');

        if (! $fisheryId) {
            abort(404);
        }

        $currentPanel = Filament::getCurrentOrDefaultPanel()?->getId();

        if ($currentPanel === 'admin') {
            $fishery = Fishery::find($fisheryId);
        } else {
            $fishery = Fishery::query()->forCurrentUser()->find($fisheryId);
        }

        if (! $fishery) {
            abort(404);
        }
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

    public static function isWizard($livewire = null): bool
    {
        if (request()->query('wizard', false)) {
            return true;
        }

        if (
            $livewire
            && property_exists($livewire, 'wizard')
            && $livewire->wizard
        ) {
            return true;
        }

        return false;
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
                function (string $attribute, $value, \Closure $fail) {
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
                        $fishery = Fishery::find($fisheryId);
                        $component->state($fishery?->name);
                    }
                })
                ->readonly(),
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
