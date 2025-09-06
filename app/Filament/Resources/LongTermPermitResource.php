<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LongTermPermitResource\Pages;
use App\Filament\Resources\LongTermPermitResource\RelationManagers;
use App\Models\LongTermPermit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LongTermPermitResource extends Resource
{
    protected static ?string $model = LongTermPermit::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255),
                Forms\Components\DatePicker::make('valid_from'),
                Forms\Components\DatePicker::make('valid_to'),
                Forms\Components\TextInput::make('fishery_id')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('price')
                    ->numeric()
                    ->default(null)
                    ->prefix('$'),
                Forms\Components\TextInput::make('sales_limit')
                    ->numeric()
                    ->default(null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_to')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fishery_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sales_limit')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListLongTermPermits::route('/'),
            'create' => Pages\CreateLongTermPermit::route('/create'),
            'edit' => Pages\EditLongTermPermit::route('/{record}/edit'),
        ];
    }
}
