<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FisheryResource\Pages;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FisheryResource extends Resource
{
    protected static ?string $model = Fishery::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                Select::make('company_id')
                    ->required()
                    ->label(__('Company'))
                    ->options(function () {
                        if (Helper::isOwnerPanel()) {
                            $query = Company::query()->forCurrentUser();

                            return Helper::sortedCompanies($query);
                        }

                        return Helper::sortedCompanies();
                    })
                    ->hidden(function ($livewire) {
                        return Helper::isOwnerPanel()
                            && Helper::isWizard($livewire);
                    }),
                Section::make(__('Fishery address'))
                    ->schema([
                        Select::make('state_id')
                            ->label(__('State'))
                            ->options(Helper::sortStates())
                            ->searchable()
                            ->required(),
                        TextInput::make('town')
                            ->label(__('Town'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('street')
                            ->label(__('Street'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('building_number')
                            ->label(__('Building number'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('zip_code')
                            ->label(__('Postal code'))
                            ->required()
                            ->maxLength(255),
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

                                return [
                                    'address' => $address,
                                    'fishery' => $record,
                                    'street' => $street,
                                    'building_number' => $buildingNumber,
                                    'zip_code' => $zipCode,
                                    'town' => $town,
                                    'state_name' => $stateName,
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
                                $collator = new \Collator('pl_PL');
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
            ]);
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
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('manage')
                    ->label(__('Manage'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(function (Fishery $record): string {
                        return FisheryResource::getUrl('manage', [
                            'record' => $record,
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFisheries::route('/'),
            'create' => Pages\CreateFishery::route('/create'),
            'edit' => Pages\EditFishery::route('/{record}/edit'),
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
