<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FishResource\Pages\CreateFish;
use App\Filament\Resources\FishResource\Pages\EditFish;
use App\Filament\Resources\FishResource\Pages\ManageFish;
use App\Models\Fish;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FishResource extends Resource
{
    protected static ?string $model = Fish::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Fish name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Fish name'))
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
            'index' => ManageFish::route('/'),
            'create' => CreateFish::route('/create'),
            'edit' => EditFish::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Fish');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Fish');
    }

    public static function getModelLabel(): string
    {
        return __('fish');
    }
}
