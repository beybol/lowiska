<?php

namespace App\Filament\Pages\Auth;

use App\Enums\ConsentContext;
use App\Models\Document;
use App\Models\User;
use App\Services\ConsentRegistry;
use App\Services\DictionaryOptions;
use App\Services\OwnerRoleProvisioner;
use App\Services\PortalRoutes;
use App\Services\SharedFormComponents;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class Register extends \Filament\Auth\Pages\Register
{
    /**
     * Wymagane dokumenty Fisherya (049, R3) — każde konto ma od początku zapis akceptacji. Zgody nie trafiają
     * do modelu `User`; zapisuje je `ConsentRegistry` w tej samej transakcji co konto.
     */
    protected function handleRegistration(array $data): Model
    {
        $documents = $this->requiredDocuments();
        unset($data['consents']);

        $user = DB::transaction(function () use ($data, $documents): Model {
            $user = parent::handleRegistration($data);

            if ($user instanceof User) {
                (new ConsentRegistry)->recordDocuments($user, $documents, ConsentContext::Registration, null, request()->ip());
            }

            return $user;
        });

        OwnerRoleProvisioner::addOwnerRole($user);

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
                ...$this->getConsentFormComponents(),
            ]);
    }

    /**
     * Jedno pole na wymaganą wersję dokumentu Fisherya (`consents.<id wersji>`). Brak wersji obowiązującej =
     * brak pola. Etykieta i komunikat — `ConsentRegistry`.
     *
     * @return array<int, Component>
     */
    protected function getConsentFormComponents(): array
    {
        $components = [];

        foreach ($this->requiredDocuments() as $document) {
            $url = PortalRoutes::documentUrl($document);

            $components[] = Checkbox::make('consents.'.$document->getKey())
                ->label(ConsentRegistry::label($document))
                ->helperText($url === null ? null : new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener" class="underline">'.e(__('read')).'</a>'))
                ->accepted()
                ->validationMessages(['accepted' => __('Confirm: :document.', ['document' => $document->variantFor(app()->getLocale())['title']])]);
        }

        return $components;
    }

    /**
     * @return list<Document>
     */
    private function requiredDocuments(): array
    {
        return (new ConsentRegistry)->requiredPlatformDocuments();
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
            ->options(DictionaryOptions::getCountryPrefixes());
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
            ...SharedFormComponents::getSocialAuthActions('register'),
        ];
    }
}
