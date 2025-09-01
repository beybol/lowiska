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
use App\Helpers\Helper;
use Filament\Facades\Filament;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('is_verified')
                    ->hidden(fn() => Helper::isOwnerPanel())
                    ->label(__('Verified')),
                Select::make('user_id')
                    ->required()
                    ->hidden(fn() => Helper::isOwnerPanel())
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
                        Placeholder::make('Enter CSO/RENAE number below.')
                            ->content(__('Enter CSO/RENAE number below.')),
                        TextInput::make('tin')
                            ->label(__('TIN')),
                        TextInput::make('renae')
                            ->label(__('RENAE')),
                        Actions::make([
                            Action::make('fetch_cso_data')
                                ->label(__('Get data from CSO'))
                                ->action(function (Get $get, Set $set) {
                                    $tin = trim($get('tin'));
                                    $renae = trim($get('renae'));

                                    if ($tin && $renae) {
                                        if (CSOService::checkAreMatched($tin, $renae)) {
                                            $address = CSOService::fetchAddress($tin, true);
                                            Helper::setAddress($set, $address);
                                        } else {
                                            $set(
                                                'error', 
                                                __('TIN and RENAE do not match. Please check numbers and try again.'),
                                            );
                                        }
                                    } else {
                                        if ($tin) {
                                            $address = CSOService
                                                ::fetchAddress($tin, true);
                                            Helper::setAddress($set, $address);
                                        } elseif ($renae) {
                                            $address = CSOService::
                                                fetchAddress($renae);
                                            Helper::setAddress($set, $address);
                                        } else {
                                            $set(
                                                'error', 
                                                __('Please provide TIN or RENAE number to fetch data from CSO.'),
                                            );
                                        }
                                    }
                                    }),
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
                    ->options(Helper::sortStates())
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_verified')
                    ->label(__('Verified'))
                    ->hidden(fn() => Helper::isOwnerPanel()),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (Helper::isOwnerPanel()) {
            $query->forCurrentUser();
        }
        
        return $query->with('user', 'state');
    }
}
