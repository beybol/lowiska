<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use App\Models\Company;
use Filament\Forms\Components\Radio;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Tworzenie łowiska.
 *
 * W panelu właściciela jest to kreator (`Wizard`) prowadzący przez firmę →
 * weryfikację → dane łowiska. W panelu administratora zostaje płaski formularz
 * zasobu — administrator wybiera firmę z pełnej listy i nie przechodzi przez
 * ścieżkę zakładania konta właściciela.
 *
 * ⚠️ Do zadania 012 ten kreator był trzema osobnymi stronami sklejonymi flagą
 * `?wizard=1` i parametrami URL (`company`, `verified_earlier`), przez co każde
 * pęknięcie łańcucha przekierowań cicho gubiło wybraną firmę. Stan kroków trzyma
 * teraz `Wizard`, nie query string — nie wracaj do tamtego wzorca (ADR-006).
 */
class CreateFishery extends CreateRecord
{
    protected static string $resource = FisheryResource::class;

    /**
     * Tryb kroku firmy: wybór istniejącej albo utworzenie nowej.
     * Trzymany w stanie formularza (`company_mode`), nie w URL-u.
     */
    private const COMPANY_MODE_EXISTING = 'existing';

    private const COMPANY_MODE_NEW = 'new';

    public function form(Schema $schema): Schema
    {
        if (! Helper::isOwnerPanel()) {
            return parent::form($schema);
        }

        return $schema->components([
            Wizard::make([
                $this->companyChoiceStep(),
                $this->newCompanyStep(),
                $this->verificationStep(),
                $this->fisheryStep(),
            ])
                ->persistStepInQueryString()
                // Przycisk zapisu należy do OSTATNIEGO kroku kreatora. Bez tego
                // Filament zostawia pod formularzem domyślne „Utwórz" i „Utwórz
                // i utwórz kolejne", widoczne na każdym kroku — łącznie z tymi,
                // na których nie ma jeszcze czego zapisać (zadanie 012).
                ->submitAction($this->wizardSubmitAction()),
        ]);
    }

    private function wizardSubmitAction(): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-filament::button type="submit" size="sm">{{ $label }}</x-filament::button>',
            ['label' => __('Create fishery')],
        ));
    }

    /**
     * W kreatorze pod formularzem zostaje wyłącznie „Anuluj" — zapis obsługuje
     * przycisk ostatniego kroku (`submitAction()` wyżej), a „utwórz i utwórz
     * kolejne" nie ma sensu w przepływie prowadzonym krok po kroku.
     */
    protected function getFormActions(): array
    {
        if (Helper::isOwnerPanel()) {
            return [$this->getCancelFormAction()];
        }

        return parent::getFormActions();
    }

    /**
     * Czy kreator ma poprowadzić przez zakładanie nowej firmy.
     *
     * Właściciel bez żadnej firmy nie ma o co pytać — dla niego kreator zawsze
     * idzie ścieżką „nowa firma", niezależnie od stanu (ukrytego wtedy) pola
     * `company_mode`. Ukryte pole nie trafia do stanu, więc nie da się polegać
     * na jego wartości domyślnej.
     */
    private function wantsNewCompany(Get $get): bool
    {
        return ! $this->ownerHasCompanies()
            || $get('company_mode') === self::COMPANY_MODE_NEW;
    }

    private ?bool $ownerHasCompanies = null;

    /**
     * Jedyne miejsce, w którym zawęża się firmy do zalogowanego właściciela —
     * używane i przez listę opcji, i przez widoczność kroków.
     */
    private function ownerCompaniesQuery(): Builder
    {
        return Company::query()->forCurrentUser();
    }

    /**
     * Zapamiętane, bo widoczność kroków sprawdza to przy każdym renderowaniu
     * formularza — bez tego byłoby kilka zapytań `exists()` na jedno żądanie.
     */
    private function ownerHasCompanies(): bool
    {
        return $this->ownerHasCompanies ??= $this->ownerCompaniesQuery()->exists();
    }

    /**
     * Krok 1 — pytanie, czy łowisko powstaje dla firmy już zapisanej na koncie,
     * czy dla nowej.
     *
     * Cały krok znika, gdy właściciel nie ma jeszcze żadnej firmy — nie ma wtedy
     * o co pytać, a kreator zaczyna się od razu od formularza firmy.
     */
    private function companyChoiceStep(): Step
    {
        $companies = Helper::sortedCompanies($this->ownerCompaniesQuery());

        return Step::make(__('Company'))
            ->schema([
                // Wybór firmy stoi w tej samej linii co przełącznik trybu — inaczej
                // ląduje pod spodem i wygląda na niezwiązany z pytaniem wyżej.
                Grid::make(2)->schema([
                    Radio::make('company_mode')
                        ->hiddenLabel()
                        ->options([
                            self::COMPANY_MODE_EXISTING => __('Create a fishery for one of my companies'),
                            // ⚠️ Bez „poniżej" — formularz nowej firmy to OSOBNY krok,
                            // więc nic się pod spodem nie pojawia.
                            self::COMPANY_MODE_NEW => __('Create a new company'),
                        ])
                        ->default(self::COMPANY_MODE_EXISTING)
                        ->live(),
                    FisheryResource::companyField()
                        ->label(__('Choose a company'))
                        ->options($companies)
                        ->live()
                        ->required(fn (Get $get): bool => ! $this->wantsNewCompany($get))
                        ->visible(fn (Get $get): bool => ! $this->wantsNewCompany($get)),
                ]),
            ])
            ->visible(fn (): bool => $this->ownerHasCompanies());
    }

    /**
     * Krok 2 — formularz nowej firmy (z pobraniem danych z GUS).
     *
     * Pola pochodzą z `CompanyResource::formComponents()` i żyją pod
     * `statePath('company')`, żeby nie kolidowały z polami łowiska o tych samych
     * nazwach (`name`, `street`, `state_id`). Krok jest pomijany, gdy właściciel
     * wybrał firmę już istniejącą.
     */
    private function newCompanyStep(): Step
    {
        return Step::make(__('Company data'))
            ->schema([
                Group::make(CompanyResource::formComponents())
                    ->statePath('company'),
            ])
            ->visible(fn (Get $get): bool => $this->wantsNewCompany($get));
    }

    /**
     * Krok 3 — informacja o przelewie weryfikacyjnym (na razie zamockowana:
     * sam komunikat, bez logiki płatności).
     *
     * Pomijany, gdy wybrana firma jest już zweryfikowana — odpowiednik dawnego
     * parametru `verified_earlier=1` w URL-u, tylko liczony ze stanu formularza.
     */
    private function verificationStep(): Step
    {
        return Step::make(__('Verification transfer'))
            ->schema([
                Text::make(__('Transfer for 1 złoty is required to verify company.')),
            ])
            // Krok pojawia się WYŁĄCZNIE przy zakładaniu nowej firmy. Firma wybrana
            // z listy jest z założenia zweryfikowana — nie sprawdzamy tu `is_verified`,
            // bo to reguła produktowa („skoro jest na liście, to przeszła weryfikację"),
            // a nie warunek na danych. Wcześniejsza wersja pytała o `is_verified`,
            // przez co krok migotał: pokazywał się przed wybraniem firmy i znikał po.
            ->visible(fn (Get $get): bool => $this->wantsNewCompany($get));
    }

    private function fisheryStep(): Step
    {
        return Step::make(__('Fishery'))
            ->schema(FisheryResource::fisheryDetailComponents());
    }

    /**
     * Zakłada firmę, jeśli kreator jest w trybie „nowa firma", i dopiero potem
     * łowisko — `company_id` bierze się z wybranej albo świeżo utworzonej firmy.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $companyData = $data['company'] ?? [];
        $mode = $data['company_mode'] ?? null;
        unset($data['company'], $data['company_mode']);

        // O ścieżce decyduje jawnie wybrany tryb, a nie sama obecność danych
        // firmy w `$data` — pola ukrytego kroku nie muszą się dehydratować i nie
        // chcemy, żeby od tego szczegółu zależało, czy powstanie nowa firma.
        $wantsNewCompany = ! $this->ownerHasCompanies()
            || $mode === self::COMPANY_MODE_NEW;

        if ($wantsNewCompany) {
            $companyData['user_id'] = auth()->id();
            $data['company_id'] = Company::create($companyData)->id;
        }

        return static::getModel()::create($data);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (Helper::isOwnerPanel()) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    /**
     * Po utworzeniu łowiska właściciel ląduje od razu na jego stronie zarządzania
     * — to naturalny następny krok (dodanie stanowisk, pozwoleń, usług), a nie
     * powrót na listę czy do formularza edycji.
     *
     * ⚠️ Świadome odstępstwo od reguły „CRUD wraca na listę" z `CLAUDE.md`
     * (zadanie 011): kreator kończy proces zakładania łowiska, a nie zwykłe
     * dodanie rekordu do tabeli.
     */
    public function getRedirectUrl(): string
    {
        if (Helper::isOwnerPanel()) {
            return FisheryResource::getUrl('manage', ['record' => $this->getRecord()]);
        }

        return FisheryResource::getUrl('index');
    }

    public function getTitle(): string
    {
        return Helper::isOwnerPanel()
            ? __('Create fishery wizard')
            : __('Create fishery');
    }
}
