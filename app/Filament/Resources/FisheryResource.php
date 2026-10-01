<?php

namespace App\Filament\Resources;

use App\Enums\FisherySection;
use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ListFisheries;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
use App\Filament\Resources\FisheryResource\Pages\ManageAvailabilityBlocks;
use App\Filament\Resources\FisheryResource\Pages\ManageCalendar;
use App\Filament\Resources\FisheryResource\Pages\ManageDocuments;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageLongTermPermits;
use App\Filament\Resources\FisheryResource\Pages\ManagePositionGroups;
use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\FisheryResource\Pages\ManagePricing;
use App\Filament\Resources\FisheryResource\Pages\ManageRefundPolicy;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleRules;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleSettings;
use App\Filament\Resources\FisheryResource\Pages\PreviewDocumentTemplate;
use App\Models\Company;
use App\Models\Convenience;
use App\Models\Fish;
use App\Models\Fishery;
use App\Models\FisheryType;
use App\Models\FishingMethod;
use App\Models\State;
use App\Models\User;
use App\Rules\FacebookUrl;
use App\Rules\FisherySlugIsNotReserved;
use App\Rules\IbanValidation;
use App\Rules\PhoneNumber;
use App\Services\DictionaryOptions;
use App\Services\FisheryAccess;
use App\Services\FisheryImages;
use App\Services\PortalSlugs;
use App\Services\SharedFormComponents;
use Collator;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class FisheryResource extends Resource
{
    protected static ?string $model = Fishery::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    /**
     * ⚠️ Jedna kolumna sekcji na pełnej szerokości (`columns(1)`), siatka WEWNĄTRZ sekcji — domyślne dwie
     * kolumny formularza stawiały sekcje obok siebie (`panel-admina.md` §2). Układ: `FisherySection`.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(static::fisheryDetailComponents(withCompany: true));
    }

    /**
     * Układa komponenty w sekcje wg `FisherySection` — JEDEN dom układu formularza i podglądu „Dane łowiska"
     * (zadanie 038, R5/R6). Komponenty układu (`Grid`, `Section`) nie mają `statePath()`, więc ścieżki stanu
     * pól (`street`, nie `address.street`) się nie zmieniają.
     *
     * ⚠️ Każda sekcja enumu musi dostać komponenty — brak to błąd programisty, nie pusta sekcja.
     *
     * @param  array<string, array<int, mixed>>  $components  klucz `FisherySection::value` → pola albo wpisy
     * @return array<int, Grid|Section>
     */
    public static function layout(array $components): array
    {
        $sections = [];

        foreach (FisherySection::cases() as $section) {
            if (! array_key_exists($section->value, $components)) {
                throw new InvalidArgumentException("Brak komponentów dla sekcji [{$section->value}].");
            }

            $container = $section->hasFrame()
                ? Section::make($section->label())
                : Grid::make();

            $sections[] = $container
                ->columns(['default' => 1, 'md' => $section->columns()])
                ->schema($components[$section->value]);
        }

        return $sections;
    }

    /**
     * Adres strony łowiska w portalu — edytowalny WYŁĄCZNIE w panelu admina (zadanie 030).
     *
     * ⚠️ Pole istnieje tylko w tym schemacie, więc w panelu właściciela Filament nie przyjmie
     * wartości sluga nawet z podmienionego stanu Livewire — ukryte pole nie jest dehydratowane.
     * Przy tworzeniu slug powstaje sam, z nazwy (`Fishery::booted()`), a właściciel widzi go
     * tylko do odczytu na stronie „Dane łowiska".
     */
    public static function slugField(): TextInput
    {
        return TextInput::make('slug')
            ->label(__('Page address (slug)'))
            ->helperText(__('Changing it invalidates the old address of the fishery page, including the short link — there is no redirect from the old one.'))
            ->visible(fn (string $operation, ?Fishery $record): bool => $operation === 'edit'
                && $record !== null
                && FisheryAccess::isAdminPanel()
                && Gate::allows('updateSlug', $record))
            ->required()
            ->maxLength(PortalSlugs::MAX_LENGTH)
            ->regex(PortalSlugs::PATTERN)
            ->validationMessages(['regex' => __('Use lowercase letters, digits and single hyphens only.')])
            // ⚠️ Slug jest też krótkim adresem wprost pod domeną (ADR-021) — nie może zająć adresu
            // aplikacji. Przy ręcznej zmianie to błąd, nie sufiks: admin wybiera adres świadomie.
            ->rules([new FisherySlugIsNotReserved])
            // Tabela, nie model: łowiska usunięte miękko też zajmują slug.
            ->unique(table: Fishery::class, ignoreRecord: true);
    }

    /**
     * Kontakt na łowisku i adresy w sieci — pokazywane wędkarzom w portalu (zadanie 030).
     *
     * ⚠️ Kontakt jest NA ŁOWISKU, nie na firmie: jedna firma prowadzi kilka łowisk z różnymi
     * numerami (portal-v3 §6).
     */
    /**
     * @return array<int, TextInput>
     */
    public static function contactComponents(): array
    {
        return [
            TextInput::make('phone')
                ->label(__('Phone'))
                ->tel()
                ->maxLength(PhoneNumber::MAX_LENGTH)
                ->rules([new PhoneNumber]),
            TextInput::make('email')
                ->label(__('E-mail'))
                ->email()
                ->maxLength(255),
            TextInput::make('contact_hours')
                ->label(__('Contact hours'))
                ->placeholder(__('e.g. 8:00–20:00, also SMS'))
                ->maxLength(255),
            TextInput::make('website_url')
                ->label(__('Website'))
                ->url()
                ->rules(['url:http,https'])
                ->maxLength(255),
            TextInput::make('facebook_url')
                ->label(__('Facebook page'))
                ->url()
                ->rules([new FacebookUrl])
                ->maxLength(255),
        ];
    }

    /**
     * Pole wyboru firmy dla płaskiego formularza (panel admina i edycja).
     *
     * W kreatorze zakładania łowiska (`Pages\CreateFishery`) firmę wybiera
     * osobny krok `Wizard`, więc to pole tam nie występuje — patrz ADR-006.
     */
    public static function companyField(): Select
    {
        return Select::make('company_id')
            ->required()
            ->label(__('Company'))
            ->options(function () {
                if (FisheryAccess::isOwnerPanel()) {
                    $query = Company::query()->forCurrentUser();

                    return DictionaryOptions::sortedCompanies($query);
                }

                return DictionaryOptions::sortedCompanies();
            });
    }

    /** Flagi wymagań wobec wędkarza — z trzecim stanem „nie podano" (zadanie 021). */
    public const ANGLER_RULE_FLAGS = ['fishing_license_required', 'no_kill', 'campfires_banned'];

    /**
     * @return array<int, mixed>
     */
    public static function anglerRuleComponents(): array
    {
        $flag = static fn (string $name, string $label): Select => Select::make($name)
            ->label($label)
            ->options([1 => __('Yes'), 0 => __('No')])
            ->placeholder(__('Not specified'));

        return [
            $flag('fishing_license_required', __('Fishing licence required')),
            TextInput::make('rods_included')
                ->label(__('Rods included in the price'))
                ->integer()
                ->minValue(0)
                ->maxValue(20)
                ->placeholder(__('Not specified')),
            $flag('no_kill', __('No-kill (fish can not be taken)')),
            $flag('campfires_banned', __('Campfires banned')),
        ];
    }

    /**
     * Pola łowiska ułożone w sekcje (`layout()`) — edycja w obu panelach i ostatni krok kreatora
     * (`Pages\CreateFishery`) używają tych samych definicji (zadanie 012).
     *
     * `$withCompany` — płaski formularz (edycja) ma w sekcji „Podstawowe" firmę i slug; kreator wybiera
     * firmę osobnym krokiem (ADR-006), więc tam ich nie ma.
     *
     * ⚠️ Zmieniasz tu pole albo sekcję — zmień też wpis w podglądzie (`Pages\ManageFishery::infolist()`),
     * który układa ten sam `FisherySection`.
     *
     * @return array<int, Grid|Section>
     */
    public static function fisheryDetailComponents(bool $withCompany = false): array
    {
        return static::layout([
            FisherySection::Basic->value => [
                ...($withCompany ? [static::companyField()] : []),
                TextInput::make('name')
                    ->label(__('Fishery name'))
                    ->required()
                    // Z nazwy powstaje slug, czyli krótki adres łowiska (ADR-021) — krótsza nazwa
                    // dawałaby slug zderzający się z prefiksem języka i od razu z sufiksem (zadanie 031).
                    ->minLength(Fishery::MIN_NAME_LENGTH)
                    ->maxLength(255),
                ...($withCompany ? [static::slugField()] : []),
                Select::make('user_id')
                    ->required()
                    ->hidden(fn () => FisheryAccess::isOwnerPanel())
                    ->label(__('Fishery entered by'))
                    ->disabled()
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(function (User $user) {
                        return $user->getFilamentName();
                    })
                    ->default(function (?Fishery $record) {
                        return $record === null
                            ? auth()->id()
                            : $record->user_id;
                    }),
            ],
            FisherySection::Description->value => [
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->hiddenLabel()
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                    ->columnSpanFull(),
            ],
            FisherySection::Address->value => [
                // ⚠️ `live(onBlur: true)` na polach adresu jest wymagane przez podgląd mapy obok:
                // `ViewField` liczy adres SERWEROWO w `viewData()`, więc musi zostać przerenderowany
                // po zmianie któregokolwiek z tych pól (zadanie 012). `Group` i `Grid` nie mają
                // `statePath()` — stan zostaje pod `street`, `town`…
                Group::make([
                    Select::make('state_id')
                        ->label(__('State'))
                        ->options(DictionaryOptions::sortStates())
                        ->searchable()
                        ->required()
                        ->live(),
                    TextInput::make('town')
                        ->label(__('Town'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        TextInput::make('street')
                            ->label(__('Street'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->columnSpan(['default' => 1, 'md' => 3]),
                        TextInput::make('building_number')
                            ->label(__('Building number'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),
                    ]),
                    TextInput::make('zip_code')
                        ->label(__('Postal code'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                ]),
                ViewField::make('map_preview')
                    ->label(__('Map Preview'))
                    ->view('filament.forms.map-preview')
                    ->viewData(fn (?Fishery $record, $get): array => static::mapPreviewData(
                        $record,
                        $get('street') ?? $record?->street,
                        $get('building_number') ?? $record?->building_number,
                        $get('zip_code') ?? $record?->zip_code,
                        $get('town') ?? $record?->town,
                        $get('state_id') ?? $record?->state_id,
                    )),
                RichEditor::make('directions')
                    ->label(__('Directions'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                    ->maxLength(255)
                    ->columnSpanFull(),
            ],
            FisherySection::Contact->value => static::contactComponents(),
            FisherySection::Water->value => [
                TextInput::make('area')
                    ->label(__('Area (in hectares)'))
                    ->required()
                    ->numeric(),
                TextInput::make('avg_depth')
                    ->label(__('Average depth (in meters)'))
                    ->numeric(),
                TextInput::make('max_depth')
                    ->label(__('Maximum depth (in meters)'))
                    ->numeric(),
                Select::make('dominant_fish_id')
                    ->label(__('Dominant fish'))
                    ->relationship('dominantFish', 'name'),
                CheckboxList::make('fishery_types')
                    ->relationship('fisheryTypes', 'name')
                    ->label(__('Fishery types'))
                    ->columns(['default' => 1, 'md' => 4])
                    ->columnSpanFull()
                    ->hidden(FisheryType::count() === 0),
                CheckboxList::make('fishing_methods')
                    ->options(function () {
                        $collator = new Collator('pl_PL');
                        $methods = FishingMethod::all()
                            ->mapWithKeys(function ($method) {
                                return [
                                    $method->id => __($method->name),
                                ];
                            });
                        $sorted = $methods->toArray();
                        $collator->asort($sorted);

                        return $sorted;
                    })
                    ->label(__('Fishing methods'))
                    ->columns(['default' => 1, 'md' => 4])
                    ->columnSpanFull()
                    ->hidden(FishingMethod::count() === 0),
                CheckboxList::make('fish')
                    ->relationship('fish', 'name')
                    ->label(__('Available fish'))
                    ->columns(['default' => 1, 'md' => 4])
                    ->columnSpanFull()
                    ->hidden(Fish::count() === 0),
                RichEditor::make('records')
                    ->label(__('Fishery records'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                    ->columnSpanFull(),
            ],
            // ⚠️ Wymagania wobec wędkarza (zadanie 021) — bieżące dane łowiska, bez wersji. Każde ma stan
            // „nie podano": flagi to `Select` z pustą opcją, nie `Toggle`, który zawsze niesie „nie".
            FisherySection::AnglerRules->value => self::anglerRuleComponents(),
            FisherySection::Conveniences->value => [
                CheckboxList::make('conveniences')
                    ->relationship('conveniences', 'name')
                    ->label(__('Conveniences'))
                    ->hiddenLabel()
                    ->columns(['default' => 1, 'md' => 4])
                    ->hidden(Convenience::count() === 0),
            ],
            FisherySection::Map->value => [
                self::imageUpload(FisheryImages::MAP)
                    ->label(__('Fishery map'))
                    ->hiddenLabel()
                    ->imagePreviewHeight('320'),
            ],
            FisherySection::Gallery->value => [
                self::imageUpload(FisheryImages::GALLERY)
                    ->multiple()
                    // Kolejność z panelu; pierwsze zdjęcie jest okładką (zadanie 036, R1).
                    ->reorderable()
                    // ⚠️ FilePond domyślnie DOPISUJE nowe pliki NA POCZĄTEK listy — bez tego wybrane A, B, C
                    // zapisują się jako C, B, A i okładką zostaje ostatni wybrany plik.
                    ->appendFiles()
                    // Kafelki zamiast podglądów na pełną szerokość — pięć zdjęć to były ekrany przewijania (038).
                    ->panelLayout('grid')
                    ->imagePreviewHeight('180')
                    ->label(__('Gallery images'))
                    ->hiddenLabel()
                    ->helperText(__('Drag to change the order — the first photo is the cover.')),
            ],
            FisherySection::Billing->value => [
                Select::make('currency_id')
                    ->label(__('Currency for settlement'))
                    ->relationship('currency', 'name'),
                TextInput::make('bank_account_number')
                    ->label(__('Bank account number (IBAN)'))
                    ->rules([new IbanValidation])
                    ->placeholder('PL 26 2030 0003 0002 0001 1111 1001')
                    ->helperText(__('Enter valid international IBAN.'))
                    ->maxLength(35),
            ],
        ]);
    }

    /**
     * Dane podglądu mapy adresu — wspólne dla formularza (stan pól na żywo) i podglądu „Dane łowiska" (rekord).
     *
     * Podgląd ma sens dopiero przy komplecie pól adresu — widok wyłącza wtedy mapę zamiast pokazywać
     * przypadkowe miejsce (zadanie 012).
     *
     * @return array{address: string, hasCompleteAddress: bool, fishery: Fishery|null}
     */
    public static function mapPreviewData(
        ?Fishery $record,
        ?string $street,
        ?string $buildingNumber,
        ?string $zipCode,
        ?string $town,
        mixed $stateId,
    ): array {
        $street = (string) $street;
        $buildingNumber = (string) $buildingNumber;
        $zipCode = (string) $zipCode;
        $town = (string) $town;
        $stateName = '';

        if ($stateId) {
            $state = State::find($stateId);
            $stateName = $state ? __($state->name) : '';
        }

        $address = implode(', ', array_filter([
            trim($street.' '.$buildingNumber),
            trim($zipCode.' '.$town),
            $stateName,
        ]));

        return [
            'address' => $address,
            'hasCompleteAddress' => filled(trim($street))
                && filled(trim($buildingNumber))
                && filled(trim($zipCode))
                && filled(trim($town))
                && filled(trim($stateName)),
            'fishery' => $record,
        ];
    }

    /**
     * Pole zdjęcia łowiska w medialibrary (zadanie 036, ADR-023).
     *
     * ⚠️ Bez `->disk()` — plugin bierze `filament.default_filesystem_disk` (`panel-admina.md` §1).
     * ⚠️ `image()` to samo `image/*`, a `finfo` zwraca dla SVG `image/svg+xml` — plik ze skryptem przechodził
     * walidację. Panel właściciela ma otwartą samorejestrację, więc formularz jest osiągalny z internetu.
     * ⚠️ Skalowanie w przeglądarce (do 2560 px, bez powiększania) to tylko optymalizacja typowej ścieżki —
     * da się je ominąć, więc serwer i tak oczyszcza każdy plik (`SanitizingMediaFilesystem`).
     */
    private static function imageUpload(string $collection): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($collection)
            ->collection($collection)
            ->image()
            ->acceptedFileTypes(FisheryImages::ACCEPTED_MIME_TYPES)
            ->imageResizeMode('contain')
            ->imageResizeTargetWidth((string) FisheryImages::ORIGINAL_MAX)
            ->imageResizeTargetHeight((string) FisheryImages::ORIGINAL_MAX)
            ->imageResizeUpscale(false)
            ->visibility('public');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label(__('Company'))
                    ->sortable(),
                TextColumn::make('town')
                    ->label(__('Town'))
                    ->searchable(),
                TextColumn::make('published_at')
                    ->label(__('In the portal'))
                    ->date()
                    ->placeholder(__('Not published'))
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('manage')
                    ->label(__('Manage'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(function (Fishery $record): string {
                        return FisheryResource::getUrl('manage', [
                            'record' => $record,
                        ]);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * ⚠️ PUSTE i takie ma zostać. Ekrany podrzędne łowiska są od zadania 016 STRONAMI
     * w sub-nawigacji rekordu, nie zakładkami RelationManagerów — patrz
     * `getRecordSubNavigation()` i ADR-006 (aktualizacja z zadania 016).
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Jedna nawigacja dla wszystkich ekranów jednego łowiska — listy i ustawienia
     * obok siebie, bez rozróżnienia widocznego dla operatora.
     *
     * ⚠️ Kolejność jest tu jedyną definicją kolejności w interfejsie. Adresy składa się
     * po KLASIE STRONY (`Page::getRouteName()`), więc przestawienie tej listy nie może
     * już przekierować zapisu na cudzą sekcję — inaczej niż dawny parametr `?relation=N`.
     *
     * @return array<int, NavigationItem>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            // Kolejność idzie od tego, co operator ustawia NAJPIERW i najrzadziej zmienia,
            // do tego, co dokłada w trakcie sezonu. Blokady są ostatnie, bo są reakcją
            // na zdarzenie, a nie częścią konfiguracji zakładanej na starcie.
            ManageFishery::class,
            ManageSaleSettings::class,
            ManageSaleRules::class,
            ManagePricing::class,
            // Kalendarz stoi ZARAZ ZA konfiguracja: trzy ekrany ustawien, a po nich ich skutek.
            ManageCalendar::class,
            // Polityka zwrotu za kalendarzem — niczego w nim nie zmienia (zadanie 021).
            ManageRefundPolicy::class,
            ManagePositions::class,
            ManagePositionGroups::class,
            ManageAdditionalServices::class,
            ManageLongTermPermits::class,
            ManageAvailabilityBlocks::class,
            // Dokumenty na samym końcu — zmieniają się najrzadziej (zadanie 021).
            ManageDocuments::class,
        ]);
    }

    /**
     * ⚠️ `Start`, nie `Top` — i to jest decyzja o SKALOWANIU, nie o guście.
     *
     * Zakładki u góry (`.fi-tabs`) to `display:flex; overflow-x:auto` bez zawijania,
     * a Filament nie ma przełącznika, który by to zmienił. Siedem polskich etykiet już
     * się nie mieściło i pojawiał się przewijak; makieta zapowiada docelowo około
     * dziesięciu sekcji, więc problem tylko by narastał. Lista pionowa rośnie w dół
     * i nie ma tego ograniczenia.
     *
     * ⚠️ Panel właściciela **nie ma** paska bocznego (`OwnerPanelProvider` ustawia
     * `topNavigation()`), więc sub-nawigacja jest tam jedynym paskiem. Panel
     * administratora ma własny — dlatego dostał `sidebarCollapsibleOnDesktop()`,
     * żeby dało się go zwinąć i oddać szerokość formularzom.
     */
    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFisheries::route('/'),
            'create' => CreateFishery::route('/create'),
            'edit' => EditFishery::route('/{record}/edit'),
            'manage' => ManageFishery::route('/{record}/manage'),
            // Zakładka konfiguracyjna huba jest STRONĄ ZASOBU, nie stroną panelu:
            // `FisheryResource` jest zarejestrowany w obu panelach, więc strona
            // trafia do obu bez dotykania providerów (ADR-006, aktualizacja 015).
            'sale-settings' => ManageSaleSettings::route('/{record}/sale-settings'),
            'sale-rules' => ManageSaleRules::route('/{record}/sale-rules'),
            'pricing' => ManagePricing::route('/{record}/pricing'),
            'calendar' => ManageCalendar::route('/{record}/calendar'),
            'positions' => ManagePositions::route('/{record}/positions'),
            'position-groups' => ManagePositionGroups::route('/{record}/position-groups'),
            'availability-blocks' => ManageAvailabilityBlocks::route('/{record}/availability-blocks'),
            'additional-services' => ManageAdditionalServices::route('/{record}/additional-services'),
            'long-term-permits' => ManageLongTermPermits::route('/{record}/long-term-permits'),
            'refund-policy' => ManageRefundPolicy::route('/{record}/refund-policy'),
            'documents' => ManageDocuments::route('/{record}/documents'),
            'document-template-preview' => PreviewDocumentTemplate::route('/{record}/document-templates/preview'),
        ];
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function getNavigationLabel(): string
    {
        return __('Fisheries');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Fisheries');
    }

    public static function getModelLabel(): string
    {
        return __('fishery');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // ⚠️ `! isAdminPanel()`, a nie `isOwnerPanel()` — tak samo jak
        // `FisheryAccess::scopeToOwnedFisheries()` i domyślne zawężenie `findFishery()`.
        // Przy panelu zarejestrowanym, ale nie-adminowym ten zapis ZAWĘŻA, odwrotny nie.
        // ⚠️ Poza kontekstem panelu żaden z nich nie zawęża — patrz `autoryzacja.md` §4.
        if (! FisheryAccess::isAdminPanel()) {
            // ⚠️ Zawężenie typu TYLKO na potrzeby wywołania scope'u. Filament deklaruje
            // `Builder<Model>`, więc analiza statyczna nie widziała tu scope'ów modelu
            // (`forCurrentUser()` miało własny wpis w baseline). Zawężenia nie da się
            // przenieść na zwracany typ — `Builder` nie jest kowariantny po modelu.
            /** @var Builder<Fishery> $scopedQuery */
            $scopedQuery = $query;
            $scopedQuery->forCurrentUser();
        }

        return $query->with('user', 'company', 'state');
    }
}
