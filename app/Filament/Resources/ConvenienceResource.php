<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConvenienceResource\Pages\CreateConvenience;
use App\Filament\Resources\ConvenienceResource\Pages\ManageConveniences;
use App\Models\Convenience;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConvenienceResource extends Resource
{
    protected static ?string $model = Convenience::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Convenience name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Convenience name'))
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
            'index' => ManageConveniences::route('/'),
            'create' => CreateConvenience::route('/create'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Conveniences');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Conveniences');
    }

    public static function getModelLabel(): string
    {
        return __('convenience');
    }
}
