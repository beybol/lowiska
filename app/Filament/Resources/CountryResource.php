<?php

namespace App\Filament\Resources;

use App\Models\Country;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\ImportAction;
use App\Filament\Imports\CountryImporter;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\CountryResource\Pages\CreateCountry;
use App\Filament\Resources\CountryResource\Pages\EditCountry;
use App\Filament\Resources\CountryResource\Pages\ListCountries;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Forms\Components\Toggle;

class CountryResource extends Resource
{
    protected static ?string $model = Country::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->columnSpanFull(),
                TextInput::make('prefix')
                    ->label(__('Country prefix'))
                    ->maxLength(10)
                    ->unique(ignoreRecord: true)
                    ->placeholder(__('Enter country prefix')),
                TextInput::make('country_name')
                    ->label(__('Country name'))
                    ->maxLength(100)
                    ->placeholder(__('Enter country name'))
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label(__('Active'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('prefix')
                    ->label(__('Country prefix'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('country_name')
                    ->label(__('Country name'))
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                ImportAction::make()
                    ->importer(CountryImporter::class),
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
            'index' => ListCountries::route('/'),
            'create' => CreateCountry::route('/create'),
            'edit' => EditCountry::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Countries');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Countries');
    }

    public static function getModelLabel(): string {
        return __('country');
    }
}
