<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConvenienceResource\Pages;
use App\Filament\Resources\ConvenienceResource\RelationManagers;
use App\Models\Convenience;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class ConvenienceResource extends Resource
{
    protected static ?string $model = Convenience::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageConveniences::route('/'),
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
