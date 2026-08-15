<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StateResource\Pages\CreateState;
use App\Filament\Resources\StateResource\Pages\EditState;
use App\Filament\Resources\StateResource\Pages\ListStates;
use App\Models\Country;
use App\Models\State;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StateResource extends Resource
{
    protected static ?string $model = State::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                        if ($record && $record->country && ! $record->country->is_active) {
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
                    ->formatStateUsing(fn ($state) => __($state))
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
            'index' => ListStates::route('/'),
            'create' => CreateState::route('/create'),
            'edit' => EditState::route('/{record}/edit'),
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

    public static function getModelLabel(): string
    {
        return __('state');
    }
}
