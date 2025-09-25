<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdditionalServiceResource\Pages;
use App\Filament\Resources\AdditionalServiceResource\RelationManagers;
use App\Models\AdditionalService;
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
use Filament\Forms\Components\RichEditor;
use App\Helpers\Helper;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\ToggleColumn;

class AdditionalServiceResource extends Resource
{
    protected static ?string $model = AdditionalService::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                ...Helper::getFisheryFields(),
                TextInput::make('name')
                    ->label(__('Additional service name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('Is additional service active')),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->toolbarButtons(Helper::getRichEditorOptions()),
                Helper::getPriceInput(),
                TextInput::make('available_count')
                    ->label(__('Available count'))
                    ->numeric()
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->helperText(__('Enter 0 for unlimited sales.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label(__('Is additional service active')),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->searchable()
                    ->formatStateUsing(function (string $state) {
                        return strip_tags($state);
                    })
                    ->limit(20),
                TextColumn::make('price')
                    ->label(__('Price'))
                    ->money()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Additional service name'))
                    ->searchable(),
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
            'index' => Pages\ListAdditionalServices::route('/'),
            'create' => Pages\CreateAdditionalService::route('/create'),
            'edit' => Pages\EditAdditionalService::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
