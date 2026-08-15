<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FishingMethodResource\Pages\CreateFishingMethod;
use App\Filament\Resources\FishingMethodResource\Pages\EditFishingMethod;
use App\Filament\Resources\FishingMethodResource\Pages\ManageFishingMethods;
use App\Models\FishingMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FishingMethodResource extends Resource
{
    protected static ?string $model = FishingMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Fishing method name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Fishing method name'))
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFishingMethods::route('/'),
            'create' => CreateFishingMethod::route('/create'),
            'edit' => EditFishingMethod::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Fishing methods');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Fishing methods');
    }

    public static function getModelLabel(): string
    {
        return __('fishing method');
    }
}
