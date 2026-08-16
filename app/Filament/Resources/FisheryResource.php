<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FisheryResource\Pages\CreateFishery;
use App\Filament\Resources\FisheryResource\Pages\EditFishery;
use App\Filament\Resources\FisheryResource\Pages\ListFisheries;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Helpers\Helper;
use App\Models\Company;
use App\Models\Convenience;
use App\Models\Fish;
use App\Models\Fishery;
use App\Models\FisheryType;
use App\Models\FishingMethod;
use App\Models\State;
use App\Models\User;
use App\Rules\IbanValidation;
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
                if (Helper::isOwnerPanel()) {
                    $query = Company::query()->forCurrentUser();

                    return Helper::sortedCompanies($query);
                }

                return Helper::sortedCompanies();
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
                ->hidden(fn () => Helper::isOwnerPanel())
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
                        ->options(Helper::sortStates())
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
                        ->toolbarButtons(Helper::getRichEditorOptions())
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
                ->toolbarButtons(Helper::getRichEditorOptions())
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
                        ->toolbarButtons(Helper::getRichEditorOptions()),
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
                ->directory('maps')
                ->visibility('public')
                ->label(__('Fishery map')),
            FileUpload::make('gallery_images')
                ->multiple()
                ->image()
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
     * Zasoby podrzędne łowiska, renderowane jako zakładki huba `ManageFishery`.
     * Tabele delegują do właściwych zasobów, więc lista w zakładce i samodzielna
     * strona listy pokazują to samo (zadanie 012).
     */
    public static function getRelations(): array
    {
        return [
            LongTermPermitsRelationManager::class,
            AdditionalServicesRelationManager::class,
            PositionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFisheries::route('/'),
            'create' => CreateFishery::route('/create'),
            'edit' => EditFishery::route('/{record}/edit'),
            'manage' => ManageFishery::route('/{record}/manage'),
        ];
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

        if (Helper::isOwnerPanel()) {
            $query->forCurrentUser();
        }

        return $query->with('user', 'company', 'state');
    }
}
