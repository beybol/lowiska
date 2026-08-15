<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\EditAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\ListAdditionalServices;
use App\Helpers\Helper;
use App\Models\AdditionalService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AdditionalServiceResource extends Resource
{
    protected static ?string $model = AdditionalService::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                TextColumn::make('name')
                    ->label(__('Additional service name'))
                    ->sortable()
                    ->searchable(),
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
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListAdditionalServices::route('/'),
            'create' => CreateAdditionalService::route('/create'),
            'edit' => EditAdditionalService::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
