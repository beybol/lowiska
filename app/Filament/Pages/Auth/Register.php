<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\CountryPrefix;


class Register extends BaseRegister
{
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->model(User::class)
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getSurnameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCountryPrefixesFormComponent(),
                        $this->getPhoneFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label(__('Name'))
            ->required();
    }

    protected function getSurnameFormComponent(): Component
    {
        return TextInput::make('surname')
            ->label(__('Surname'))
            ->required();
    }

    protected function getCountryPrefixesFormComponent(): Component
    {
        return Select::make('country_prefix_id')
            ->label(__('Country prefix'))
            ->options(function () {
                $collator = new \Collator(app()->getLocale());
                $countryPrefixes = CountryPrefix::all()->map(function ($cp) {
                    return [
                        'id' => $cp->id,
                        'label' => __($cp->country_name) . ", {$cp->prefix}",
                    ];
                });
                $sorted = $countryPrefixes->sort(
                    function ($cp1, $cp2) use ($collator) {
                        return $collator
                            ->compare($cp1['label'], $cp2['label']);
                    }
                );

                return $sorted->pluck('label', 'id');
            });
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Phone (without prefix)'));
    }
}
