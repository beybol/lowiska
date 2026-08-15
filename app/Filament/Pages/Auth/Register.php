<?php

namespace App\Filament\Pages\Auth;

use App\Helpers\Helper;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class Register extends \Filament\Auth\Pages\Register
{
    protected function handleRegistration(array $data): Model
    {
        $user = parent::handleRegistration($data);
        Helper::addOwnerRole($user);

        return $user;
    }

    /**
     * ⚠️ Zadanie 009. Do Filamenta 3 formularz rejestracji budowało się przez
     * `getForms()` + `$this->makeForm()`. W Filamencie 5 `makeForm()` już nie
     * istnieje, a stronę konfiguruje się nadpisując `form(Schema $schema)`
     * (`statePath('data')` ustawia rodzic w `defaultForm()`).
     *
     * Awaria była CICHA: nadpisany `getForms()` przestał być wołany, strona
     * dalej zwracała 200, ale renderowała wyłącznie domyślne pola rodzica —
     * `surname`, `country_id` i `phone` znikały z rejestracji bez jednego
     * błędu w logach. Wyłapał to dopiero PHPStan (`makeForm()` nie istnieje),
     * nie testy funkcjonalne.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(User::class)
            ->components([
                $this->getNameFormComponent(),
                $this->getSurnameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCountryPrefixesFormComponent(),
                $this->getPhoneFormComponent(),
            ]);
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
        return Select::make('country_id')
            ->label(__('Country prefix'))
            ->options(Helper::getCountryPrefixes());
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Phone (without prefix)'));
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            ...Helper::getSocialAuthActions('register'),
        ];
    }
}
