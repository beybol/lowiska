<?php

namespace App\Filament\Resources\FisheryResource\RelationManagers;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Blokady i ograniczenia — zakładka huba „Zarządzaj łowiskiem".
 *
 * ⚠️ Wpisy są LISTĄ REKORDÓW z własnymi stronami, więc idą RelationManagerem,
 * a nie stroną ustawień (`panel-wlasciciela.md` §6).
 */
class AvailabilityBlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'availabilityBlocks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Availability blocks');
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

        // Plakietka liczy wpisy obowiązujące DZIŚ albo w przyszłości — te już
        // zakończone są historią i nie mają alarmować.
        $count = $ownerRecord->availabilityBlocks()->notEndedBefore(now())->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return AvailabilityBlockResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return AvailabilityBlockResource::table($table)
            // ⚠️ RelationManager jedzie po relacji `ownerRecord`, więc NIE przechodzi
            // przez `getEloquentQuery()` zasobu i nie dziedziczy stamtąd eager-loadu.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fishery'))
            ->headerActions([
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', AvailabilityBlock::class))
                    ->url(fn (): string => AvailabilityBlockResource::getUrl('create', [
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
                    ->visible(fn (AvailabilityBlock $record): bool => Gate::allows('update', $record))
                    ->url(fn (AvailabilityBlock $record): string => AvailabilityBlockResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ]);
    }
}
