<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StateResource\Pages;
use App\Filament\Resources\StateResource\RelationManagers;
use App\Models\State;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use App\Models\Country;

class StateResource extends Resource
{
    protected static ?string $model = State::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label(__('State name'))
                    ->maxLength(100)
                    ->required()
                    ->placeholder(__('Enter state name')),
                Select::make('country_id')
                    ->label(__('Country name'))
                    ->options(
                        Country::active()
                            ->orderBy('country_name')
                            ->pluck('country_name', 'id'),
                    )
                    ->formatStateUsing(function ($state, $record) {
                        if ($record && $record->country && !$record->country->is_active) {
                            return null;
                        }

                        return $state;
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['country' => function ($query) {
                    $query->active();
                }]);
            })
            ->columns([
                TextColumn::make('name')
                    ->label(__('State name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('country.country_name')
                    ->label(__('Country name'))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('country', function (Builder $query) use ($search) {
                            $query->where('country_name', 'like', "%{$search}%")
                                ->active();
                        });
                    }),
            ])
            ->filters([
                SelectFilter::make('country')
                    ->label(__('Country name'))
                    ->relationship(
                        'country', 
                        'country_name', 
                        fn (Builder $query) => $query->active()
                    ),
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
            'index' => Pages\ListStates::route('/'),
            'create' => Pages\CreateState::route('/create'),
            'edit' => Pages\EditState::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('States');
    }

    public static function getPluralLabel(): ?string
    {
        return __('States');
    }

    public static function getModelLabel(): string {
        return __('state');
    }
}
