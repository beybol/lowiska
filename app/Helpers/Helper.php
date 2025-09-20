<?php

namespace App\Helpers;

use App\Models\Company;
use App\Models\Country;
use Filament\Forms\Set;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use App\Models\State;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Filament\Forms\Get;
use App\Services\CSOService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Hidden;
use App\Models\Fishery;

class Helper
{
    public static function assertFisheryAccessOrAbort(?int $fisheryId = null): void
    {
        $fisheryId = $fisheryId ?? request()->get('fishery');

        if (!$fisheryId) {
            abort(404);
        }

        $currentPanel = Filament::getCurrentPanel()?->getId();

        if ($currentPanel === 'admin') {
            $fishery = Fishery::find($fisheryId);
        } else {
            $fishery = Fishery::query()->forCurrentUser()->find($fisheryId);
        }

        if (!$fishery) {
            abort(404);
        }
    }
    public static function getRichEditorOptions() {
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
        $collator = new \Collator(app()->getLocale());
        
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
                $label = __($country->country_name) . ", {$country->prefix}";

                return [$country->id => $label];
            })
            ->toArray();
    }

    public static function getSocialAuthActions(
        string $actionType = 'login'
    ): array
    {
        $currentPanel = Filament::getCurrentPanel();
        $panelId = $currentPanel ? $currentPanel->getId() : 'admin';
        
        return [
            Action::make($actionType . '_google')
                ->label(__(
                    $actionType === 'login' 
                        ? 'Login with Google' 
                        : 'Register with Google'
                    ))
                ->color('gray')
                ->icon(fn () => view('components.icons.google'))
                ->url(route('social.redirect', [
                    'provider' => 'google', 
                    'source' => $panelId
                ])),
            Action::make($actionType . '_facebook')
                ->label(__(
                    $actionType === 'login' 
                        ? 'Login with Facebook' 
                        : 'Register with Facebook'
                ))
                ->color('gray')
                ->icon(fn () => view('components.icons.facebook'))
                ->url(route('social.redirect', [
                    'provider' => 'facebook', 
                    'source' => $panelId
                ])),
        ];
    }

    public static function isOwnerPanel(): bool
    {
        $panel = Filament::getCurrentPanel();
        
        return $panel?->getId() === 'owner';
    }

    public static function sortStates() 
    {
        $collator = new \Collator(app()->getLocale());
        
        return State::all()
            ->sort(function ($state1, $state2) use ($collator) {
                return $collator->compare(
                    __($state1->name),
                    __($state2->name)
                );
            })
            ->pluck('name', 'id')
            ->map(fn($name) => __($name));
    }

    public static function sortedCompanies($query = null, $modelsOnly = false)
    {
        $collator = new \Collator(app()->getLocale());
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
            'view_any_company',
            'view_company',
            'create_company',
            'update_company',
            'delete_company',
            'delete_any_company',
            'force_delete_company',
            'force_delete_any_company',
            'restore_company',
            'restore_any_company',
            'replicate_company',
            'reorder_company',
        ];
        
        foreach ($companyPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $fisheryPermissions = [
            'view_any_fishery',
            'view_fishery',
            'create_fishery',
            'update_fishery',
            'delete_fishery',
            'delete_any_fishery',
            'force_delete_fishery',
            'force_delete_any_fishery',
            'restore_fishery',
            'restore_any_fishery',
            'replicate_fishery',
            'reorder_fishery',
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

        if (!$tin && !$renae) {
            $set(
                'error', 
                __('Please provide TIN or RENAE number to fetch data from CSO.'),
            );

            return;
        }

        if ($tin && !CSOService::isValidTIN($tin)) {
            if ($renae && !CSOService::isValidRENAE($renae)) {
                $set('error', __('Invalid TIN and RENAE format.'));

                return;
            }

            $set('error', __('Invalid TIN format.'));

            return;
        }

        if ($renae && !CSOService::isValidRENAE($renae)) {
            $set('error', __('Invalid RENAE format.'));

            return;
        }

        $company = Company::query()->findByNumber($tin, $renae)->first();

        if ($company) {
            if (!$record || $company->id !== $record->id) {
                $currentUser = Filament::auth()->user();
                $companyUser = $company->user;
                
                if ($companyUser->is($currentUser)) {
                    $set(
                        'error',
                        __('You have already entered the company.'),
                    );
                } else {
                    $adminEmail = env('ADMIN_EMAIL');
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
                $address = CSOService
                    ::fetchAddress($tin, true);
                Helper::setAddress($set, $address);
            } elseif ($renae) {
                $address = CSOService::
                    fetchAddress($renae);
                Helper::setAddress($set, $address);
            }
        }
    }

    public static function getPriceInput()
    {
        $language = app()->getLocale();

        return TextInput::make('price')
            ->label(__('Price'))
            ->when($language === 'pl', function ($component) {
                return $component
                    ->rules([
                        'nullable',
                        'regex:/^(?!0,00$)\d+,\d{2}$/',
                        'min:0.01'
                    ])
                    ->placeholder('100,00')
                    ->helperText(__('Format: 100,00 (używaj przecinka).'));
            })
            ->when($language !== 'pl', function ($component) {
                return $component
                    ->rules([
                        'nullable', 
                        'regex:/^(?!0\.00$)\d+\.\d{2}$/',
                        'min:0.01'
                    ])
                    ->step(0.01)
                    ->inputMode('decimal')
                    ->placeholder('100.00')
                    ->helperText(__('Format: 100.00 (use dot).'));
            })
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
                        $fishery = \App\Models\Fishery::find($fisheryId);
                        $component->state($fishery?->name);
                    }
                })
                ->readonly()
                ->dehydrated(false),
            Hidden::make('fishery_id')
                ->default(function ($record) {
                    if ($record && $record->fishery_id) {
                        return $record->fishery_id;
                    }
            
                    return request()->get('fishery');
                })
        ];
    }

    public static function getBackToFisheryManagementAction($fisheryId = null, $actionType = 'cancel')
    {
        $url = function () use ($fisheryId) {
            $id = $fisheryId;
            
            if (!$id) {
                $id = request()->get('fishery');
            }
            
            if (!$id && isset($this->record)) {
                $id = $this->record->fishery_id;
            }
            
            if ($id) {
                return \App\Filament\Resources\FisheryResource::getUrl('manage', ['record' => $id]);
            }

            return back();
        };

        if ($actionType === 'cancel') {
            return function($cancelAction) use ($url) {
                return $cancelAction
                    ->label(__('Back to fishery management'))
                    ->url($url);
            };
        } else {
            return \Filament\Actions\Action::make('back_to_fishery')
                ->label(__('Back to fishery management'))
                ->url($url)
                ->color('gray');
        }
    }
}
