<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FisheryTypeResource\Pages;
use App\Filament\Resources\FisheryTypeResource\RelationManagers;
use App\Models\FisheryType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class FisheryTypeResource extends Resource
{
    protected static ?string $model = FisheryType::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label(__('Fishery type name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Fishery type name'))
                    ->searchable()
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
            'index' => Pages\ManageFisheryTypes::route('/'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Fishery Types');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Fishery Types');
    }

    public static function getModelLabel(): string 
    {
        return __('fishery type');
    }
}
