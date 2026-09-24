<?php

namespace App\Services;

use App\Models\Company;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Pola i komponenty formularzy współdzielone między zasobami.
 *
 * ⚠️ `fetchDataFromCSO()` jest TUTAJ, a nie w `CSOService`, mimo nazwy: przyjmuje
 * `Get`/`Set` Filamenta i ustawia stan formularza, więc jest klejem formularza,
 * nie integracją. `CSOService` ma zostać wolny od zależności od Filamenta
 * (`docs/conventions/integracje.md`).
 */
final class SharedFormComponents
{
    public static function getFisheryFields()
    {
        return [
            TextInput::make('fishery_name')
                ->label(__('Fishery'))
                ->afterStateHydrated(function ($component, $state, $record) {
                    if ($record && $record->fishery) {
                        $component->state($record->fishery->name);
                    } elseif ($fisheryId = request()->get('fishery')) {
                        $component->state(FisheryAccess::findFishery($fisheryId)?->name);
                    }
                })
                ->readonly(),
            // ⚠️ To pole jest wyłącznie WYGODĄ formularza, nie źródłem prawdy.
            // Wartość zapisywana do bazy wymusza `FisheryAccess::forceVerifiedFishery()`
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

    /**
     * ⚠️ Nazwa pola jest PARAMETREM, bo ten sam komponent obsługuje dziś `additional_services.price`
     * i `price_rules.amount` (zadanie 018). Drugi wariant tego pola byłby drugim literałem
     * walidacji kwoty i normalizacji przecinka — a to jest dokładnie ta reguła, której
     * `CLAUDE.md` każe mieć jeden dom.
     */
    public static function getPriceInput(string $name = 'price', ?string $label = null, float $minimum = 0.01)
    {
        return TextInput::make($name)
            ->label($label ?? __('Price'))
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
                // ⚠️ Minimum jest PARAMETREM, bo należy do kontekstu, nie do komponentu.
                // Usługa dodatkowa za 0,00 zł nie ma sensu, ale stawka cennika owszem:
                // osoba towarzysząca wycenia się regułą `rate` z kwotą **0,00** i to jest
                // jedyny sposób, w jaki ma być wyceniana (O15, zadanie 018). Twarde 0,01
                // w komponencie blokowało zapis tej reguły.
                static fn (): \Closure => static function (string $attribute, $value, \Closure $fail) use ($minimum): void {
                    if (blank($value)) {
                        return;
                    }

                    if ((float) str_replace(',', '.', $value) < $minimum) {
                        $fail(__('The price must be at least :minimum.', [
                            'minimum' => number_format($minimum, 2, '.', ''),
                        ]));
                    }
                },
            ])
            ->dehydrateStateUsing(fn (?string $state) => filled($state)
                ? (float) str_replace(',', '.', $state)
                : null)
            ->default(null);
    }

    /**
     * Wybór dób tygodnia — JEDEN komponent dla weekendu i dla warunku dopłaty (zadanie 023).
     *
     * ⚠️ **Chipy, nie `CheckboxList`, i przedziały, nie nazwy dni** (`panel-wlasciciela.md` §6).
     * Wiedza o dobach mieszka w `WeekdayNights`; tu jest wyłącznie budowa pola: rząd siedmiu
     * dwuwierszowych chipów i podsumowanie pod nimi. Podsumowanie wymaga `live()`, inaczej
     * odświeżałoby się dopiero przy innym żądaniu.
     *
     * ⚠️ `ToggleButtons` przysyła wartości jako łańcuchy — rzutowanie na `int` należy do
     * wołającego, bo tylko on wie, gdzie powstaje zapisywany stan.
     *
     * @param  string  $whenEmpty  co znaczy pusty zbiór na TYM ekranie
     */
    public static function weekdayNightsInput(
        string $name,
        WeekdayNights $nights,
        string $label,
        string $whenEmpty,
        ?string $helperText = null,
    ): ToggleButtons {
        return ToggleButtons::make($name)
            ->label($label)
            ->multiple()
            ->inline()
            // ⚠️ Domknięcie, nie tablica: sygnatura `options()` deklaruje etykiety jako
            // łańcuchy, a chipy są `Htmlable` — renderer przyjmuje oba, bo przepuszcza
            // etykietę przez `e()`, które `Htmlable` zostawia nietknięte.
            ->options(static fn (): array => $nights->options())
            ->live()
            ->belowContent(static function (mixed $state) use ($nights, $whenEmpty, $helperText): array {
                $content = [Text::make($nights->summary($state, $whenEmpty))];

                if (filled($helperText)) {
                    $content[] = Text::make($helperText);
                }

                return $content;
            })
            ->columnSpanFull();
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
                self::setAddress($set, $address);
            } else {
                $set(
                    'error',
                    __('TIN and RENAE do not match. Please check numbers and try again.'),
                );
            }
        } else {
            if ($tin) {
                $address = CSOService::fetchAddress($tin, true);
                self::setAddress($set, $address);
            } elseif ($renae) {
                $address = CSOService::fetchAddress($renae);
                self::setAddress($set, $address);
            }
        }
    }
}
