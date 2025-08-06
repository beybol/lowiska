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
                    ->formatStateUsing(function ($state, ?Company $company) {
                        return $company === null 
                            ? auth()->id() 
                            : $company->user_id;
                    }),
                TextInput::make('name')
                    ->label(__('Company name'))
                    ->required(),
                TextInput::make('tin')
                    ->label(__('TIN')),
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
                TextInput::make('cso_response')
                    ->label(__('CSO response'))
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
