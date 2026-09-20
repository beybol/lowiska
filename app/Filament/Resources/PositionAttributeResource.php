<?php

namespace App\Filament\Resources;

use App\Enums\PositionAttributeType;
use App\Filament\Resources\PositionAttributeResource\Pages\CreatePositionAttribute;
use App\Filament\Resources\PositionAttributeResource\Pages\ManagePositionAttributes;
use App\Models\PositionAttribute;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Słownik cech stanowisk — WYŁĄCZNIE panel administratora.
 *
 * ⚠️ Zasób celowo nie trafia na listę `OwnerPanelProvider`. Słownik jest wspólny dla
 * całego portalu i to jest warunek, pod którym filtrowanie przez wszystkie łowiska
 * ma sens; cechy definiowane przez operatorów odebrałyby mu go (zadanie 014).
 */
class PositionAttributeResource extends Resource
{
    protected static ?string $model = PositionAttribute::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Attribute name'))
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label(__('Attribute type'))
                    ->options(PositionAttributeType::options())
                    ->default(PositionAttributeType::Flag->value)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('unit')
                    ->label(__('Unit'))
                    ->maxLength(20)
                    // Jednostka ma sens wyłącznie przy liczbie — przy fladze i wyborze
                    // z listy byłaby polem, które nic nie znaczy.
                    ->visible(fn (Get $get): bool => $get('type') === PositionAttributeType::Number->value),
                Toggle::make('is_filterable')
                    ->label(__('Filterable'))
                    ->helperText(__('Marks the attribute for the future search; the filter itself is out of scope.')),
                Repeater::make('options')
                    ->label(__('Options'))
                    ->relationship()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Option name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sort_order')
                            ->label(__('Sort order'))
                            ->numeric()
                            ->default(0),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel(__('Add option'))
                    ->visible(fn (Get $get): bool => $get('type') === PositionAttributeType::Choice->value),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Attribute name'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Attribute type'))
                    ->formatStateUsing(fn (PositionAttributeType $state): string => $state->label()),
                TextColumn::make('unit')
                    ->label(__('Unit')),
                IconColumn::make('is_filterable')
                    ->label(__('Filterable'))
                    ->boolean(),
                TextColumn::make('options_count')
                    ->label(__('Options'))
                    ->counts('options'),
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
            'index' => ManagePositionAttributes::route('/'),
            'create' => CreatePositionAttribute::route('/create'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Position attributes');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Position attributes');
    }

    public static function getModelLabel(): string
    {
        return __('position attribute');
    }
}
