<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CountryPrefixResource\Pages;
use App\Filament\Resources\CountryPrefixResource\RelationManagers;
use App\Models\CountryPrefix;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\ImportAction;
use App\Filament\Imports\CountryPrefixImporter;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;

class CountryPrefixResource extends Resource
{
    protected static ?string $model = CountryPrefix::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                    ->importer(CountryPrefixImporter::class),
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
            'index' => Pages\ListCountryPrefixes::route('/'),
            'create' => Pages\CreateCountryPrefix::route('/create'),
            'edit' => Pages\EditCountryPrefix::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Country prefixes');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Country prefixes');
    }

    public static function getModelLabel(): string {
        return __('country prefix');
    }
}
