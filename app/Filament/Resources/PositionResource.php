<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PositionResource\Pages;
use App\Filament\Resources\PositionResource\RelationManagers;
use App\Models\Position;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Forms\Components\RichEditor;
use App\Helpers\Helper;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

     protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('is_active')
                    ->label(__('Is active')),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Position name')),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->toolbarButtons(Helper::getRichEditorOptions()),
                Select::make('fishery_id')
                    ->label(__('Fishery'))
                    ->required()
                    ->relationship('fishery', 'name')
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set) => $set('long_term_permit_id', []))
                    ->disabled(fn ($context) => $context === 'edit'),
                CheckboxList::make('long_term_permit_id')
                    ->relationship('longTermPermits', 'description')
                    ->label(__('Long term permits'))
                    ->options(function (callable $get) {
                        $fisheryId = $get('fishery_id');
                        if (!$fisheryId) {
                            return [];
                        }
                        
                        return \App\Models\LongTermPermit::where('fishery_id', $fisheryId)
                            ->where('is_active', true)
                            ->pluck('description', 'id')
                            ->toArray();
                    })
                    ->reactive(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fishery_id')
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
            'index' => Pages\ListPositions::route('/'),
            'create' => Pages\CreatePosition::route('/create'),
            'edit' => Pages\EditPosition::route('/{record}/edit'),
        ];
    }
}
