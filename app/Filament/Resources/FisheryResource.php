<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ListFisheries;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
use App\Filament\Resources\FisheryResource\Pages\ManageAvailabilityBlocks;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\FisheryResource\Pages\ManageLongTermPermits;
use App\Filament\Resources\FisheryResource\Pages\ManagePositionGroups;
use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\FisheryResource\Pages\ManageSaleSettings;
use App\Models\Company;
use App\Models\Convenience;
use App\Models\Fish;
use App\Models\Fishery;
use App\Models\FisheryType;
use App\Models\FishingMethod;
use App\Models\State;
use App\Models\User;
use App\Rules\IbanValidation;
use App\Services\DictionaryOptions;
use App\Services\FisheryAccess;
use App\Services\SharedFormComponents;
use Collator;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FisheryResource extends Resource
{
    protected static ?string $model = Fishery::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            static::companyField(),
            ...static::fisheryDetailComponents(),
        ]);
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

    /**
     * Wszystkie pola łowiska POZA wyborem firmy.
     *
     * Wydzielone, żeby ostatni krok kreatora (`Pages\CreateFishery`) mógł użyć
     * dokładnie tych samych definicji, zamiast je powielać (zadanie 012).
     *
     * @return array<int, mixed>
     */
    public static function fisheryDetailComponents(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Fishery name'))
                ->required()
                ->maxLength(255),
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
            Section::make(__('Fishery address'))
                ->schema([
                    // ⚠️ `live(onBlur: true)` na polach adresu jest wymagane przez podgląd
                    // mapy niżej: `ViewField` liczy adres SERWEROWO w `viewData()`, więc
                    // musi zostać przerenderowany po zmianie któregokolwiek z tych pól.
                    // Bez tego podgląd pokazywałby adres sprzed edycji (zadanie 012).
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
                    TextInput::make('street')
                        ->label(__('Street'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    TextInput::make('building_number')
                        ->label(__('Building number'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    TextInput::make('zip_code')
                        ->label(__('Postal code'))
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    RichEditor::make('directions')
                        ->label(__('Directions'))
                        ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                        ->maxLength(255),
                    ViewField::make('map_preview')
                        ->label(__('Map Preview'))
                        ->view('filament.forms.map-preview')
                        ->viewData(function ($record, $get) {
                            $street = $get('street')
                                ?? $record?->street
                                ?? '';
                            $buildingNumber = $get('building_number')
                                ?? $record?->building_number
                                ?? '';
                            $zipCode = $get('zip_code')
                                ?? $record?->zip_code
                                ?? '';
                            $town = $get('town')
                                ?? $record?->town
                                ?? '';
                            $stateId = $get('state_id')
                                ?? $record?->state_id;
                            $stateName = '';

                            if ($stateId) {
                                $state = State::find($stateId);
                                $stateName = $state
                                    ? __($state->name)
                                    : '';
                            }

                            $address = implode(', ', array_filter([
                                trim($street.' '.$buildingNumber),
                                trim($zipCode.' '.$town),
                                $stateName,
                            ]));

                            // Podgląd mapy ma sens dopiero przy komplecie pól adresu —
                            // widok wyłącza wtedy przycisk zamiast pokazywać mapę
                            // wskazującą przypadkowe miejsce (zadanie 012).
                            $hasCompleteAddress = filled(trim($street))
                                && filled(trim($buildingNumber))
                                && filled(trim($zipCode))
                                && filled(trim($town))
                                && filled(trim($stateName));

                            return [
                                'address' => $address,
                                'hasCompleteAddress' => $hasCompleteAddress,
                                'fishery' => $record,
                            ];
                        })
                        ->columnSpanFull(),
                ]),
            RichEditor::make('description')
                ->label(__('Description'))
                ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                ->columnSpanFull(),
            Section::make(__('Fishery data'))
                ->schema([
                    CheckboxList::make('fishery_types')
                        ->relationship('fisheryTypes', 'name')
                        ->label(__('Fishery types'))
                        ->hidden(FisheryType::count() === 0),
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
                        ->hidden(FishingMethod::count() === 0),
                    TextInput::make('positions_count')
                        ->label(__('Positions count'))
                        ->required()
                        ->numeric(),
                    Select::make('dominant_fish_id')
                        ->label(__('Dominant fish'))
                        ->relationship('dominantFish', 'name'),
                    RichEditor::make('records')
                        ->label(__('Fishery records'))
                        ->toolbarButtons(SharedFormComponents::getRichEditorOptions()),
                    Select::make('currency_id')
                        ->label(__('Currency for settlement'))
                        ->relationship('currency', 'name'),
                    TextInput::make('bank_account_number')
                        ->label(__('Bank account number (IBAN)'))
                        ->rules([new IbanValidation])
                        ->placeholder('PL 26 2030 0003 0002 0001 1111 1001')
                        ->helperText(__('Enter valid international IBAN.'))
                        ->maxLength(35),
                ]),
            CheckboxList::make('conveniences')
                ->relationship('conveniences', 'name')
                ->label(__('Conveniences'))
                ->hidden(Convenience::count() === 0),
            CheckboxList::make('fish')
                ->relationship('fish', 'name')
                ->label(__('Available fish'))
                ->hidden(Fish::count() === 0),
            FileUpload::make('map_image_path')
                ->image()
                // ⚠️ `image()` to samo `image/*`, a `finfo` zwraca dla SVG
                // `image/svg+xml` — plik ze skryptem przechodził walidację i lądował
                // na dysku z rozszerzeniem `.svg`. Panel właściciela ma otwartą
                // samorejestrację, więc formularz jest osiągalny z internetu.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->directory('maps')
                ->visibility('public')
                ->label(__('Fishery map')),
            FileUpload::make('gallery_images')
                ->multiple()
                ->image()
                // ⚠️ Jak wyżej — `image()` przepuszcza SVG.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->directory('galleries')
                ->visibility('public')
                ->label(__('Gallery images')),
        ];
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
            ManagePositions::class,
            ManagePositionGroups::class,
            ManageAdditionalServices::class,
            ManageLongTermPermits::class,
            ManageAvailabilityBlocks::class,
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
            'positions' => ManagePositions::route('/{record}/positions'),
            'position-groups' => ManagePositionGroups::route('/{record}/position-groups'),
            'availability-blocks' => ManageAvailabilityBlocks::route('/{record}/availability-blocks'),
            'additional-services' => ManageAdditionalServices::route('/{record}/additional-services'),
            'long-term-permits' => ManageLongTermPermits::route('/{record}/long-term-permits'),
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
