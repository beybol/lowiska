<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FishResource\Pages;
use App\Filament\Resources\FishResource\RelationManagers;
use App\Models\Fish;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class FishResource extends Resource
{
    protected static ?string $model = Fish::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
            'index' => Pages\ManageFish::route('/'),
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
