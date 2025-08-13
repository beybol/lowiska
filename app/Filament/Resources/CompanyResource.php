<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers;
use App\Models\Company;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\User;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use App\Services\CSOService;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('is_verified')
                    ->label(__('Verified')),
                Select::make('user_id')
                    ->required()
                    ->label(__('Company entered by'))
                    ->disabled()
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(function (User $user) {
                        return $user->getFilamentName();
                    })
                    ->default(function (?Company $record) {
                        return $record === null 
                            ? auth()->id() 
                            : $record->user_id;
                    }),
                Section::make(__('Get data from CSO'))
                    ->schema([
                        TextInput::make('tin')
                            ->label(__('TIN')),
                        Actions::make([
                            Action::make('fetch_cso_data')
                                ->label(__('Fetch'))
                                ->action(function (Get $get, Set $set) {
                                    $address = CSOService
                                        ::fetchAddress($get('tin'));
                                    if (isset($address['error'])) {
                                        $set('error', $address['error']);
                                    } else {
                                        $set(
                                            'cso_response', 
                                            $address['cso_response']
                                        );
                                        $name = $address['name'] ?? null;
                                        $set('name', $name);
                                        $renae = $address['renae'] ?? null;
                                        $set('renae', $renae);
                                        $street = $address['street'] ?? null;
                                        $set('street', $street);
                                        $hN = $address['house_number'] 
                                            ?? null;
                                        $set('house_number', $hN);
                                        $isFlatNumber = array_key_exists(
                                            'flat_number',
                                            $address
                                        );
                                        $fN = $address['flat_number'] 
                                            ?? null;
                                        $set('flat_number', $fN);
                                        $postalCode = $address['postal_code']
                                            ?? null;
                                        $set('postal_code', $postalCode);
                                        $city = $address['city'] ?? null;
                                        $set('city', $city);
                                        $stateId = $address['state_id'] 
                                            ?? null;
                                        $set('state_id', $stateId);
                                        $set('error', null);
                                    }
                                })
                        ]),
                        Placeholder::make('error')
                            ->content(function (Get $get) {
                                return new HTMLString(
                                    '<div class="text-danger-600">'
                                        . $get('error')
                                        . '</div>'
                                );
                            })
                            ->label(''),
                        TextInput::make('cso_response')
                            ->label(__('CSO response'))
                            ->readonly(),
                    ]),
                TextInput::make('name')
                    ->label(__('Company name'))
                    ->required(),
                TextInput::make('renae')
                    ->label(__('RENAE')),
                TextInput::make('street')
                    ->label(__('Street'))
                    ->required(),
                TextInput::make('house_number')
                    ->label(__('House number'))
                    ->required(),
                TextInput::make('flat_number')
                    ->label(__('Flat number')),
                TextInput::make('postal_code')
                    ->label(__('Postal code'))
                    ->required(),
                TextInput::make('city')
                    ->label(__('City'))
                    ->required(),
                Select::make('state_id')
                    ->label(__('State'))
                    ->relationship('state', 'name')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_verified')
                    ->label(__('Verified')),
                TextColumn::make('name')
                    ->label(__('Company name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('city')
                    ->label(__('City'))
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Companies');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Companies');
    }

    public static function getModelLabel(): string {
        return __('company');
    }
}
