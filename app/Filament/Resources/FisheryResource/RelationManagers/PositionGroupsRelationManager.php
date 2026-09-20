<?php

namespace App\Filament\Resources\FisheryResource\RelationManagers;

use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\PositionGroupResource;
use App\Models\Fishery;
use App\Models\PositionGroup;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Grupy stanowisk — zakładka huba „Zarządzaj łowiskiem".
 *
 * ⚠️ Grupy są LISTĄ REKORDÓW, więc idą RelationManagerem, a nie stroną ustawień —
 * to jest ta pierwsza strona granicy z `panel-wlasciciela.md` §6.
 */
class PositionGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'positionGroups';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Position groups');
    }

    /**
     * ⚠️ `FisheryResource::getRelations()` obowiązuje WSZYSTKIE strony zasobu, więc bez
     * tego zakładka dokleja się także do formularza edycji łowiska (zadanie 012).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ManageFishery::class;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        assert($ownerRecord instanceof Fishery);

        $count = $ownerRecord->positionGroups()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return PositionGroupResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return PositionGroupResource::table($table)
            // ⚠️ RelationManager jedzie po relacji `ownerRecord`, więc NIE przechodzi
            // przez `getEloquentQuery()` zasobu i nie dziedziczy stamtąd eager-loadu.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fishery'))
            ->headerActions([
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', PositionGroup::class))
                    ->url(fn (): string => PositionGroupResource::getUrl('create', [
                        'fishery' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            // ⚠️ Akcje CRUD-owe w zakładce huba NIE DZIAŁAJĄ: `isReadOnly()` jest prawdą
            // na stronie `ViewRecord`, a autoryzacja odmawia PO KLASIE akcji i robi to
            // po cichu. Stąd zwykłe `Action` (`panel-wlasciciela.md` §2).
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (PositionGroup $record): bool => Gate::allows('update', $record))
                    ->url(fn (PositionGroup $record): string => PositionGroupResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ]);
    }
}
