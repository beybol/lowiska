<?php

namespace App\Filament\Resources\FisheryResource\RelationManagers;

use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\PositionResource;
use App\Models\Fishery;
use App\Models\Position;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Stanowiska łowiska — lista widoczna wprost w zakładce huba „Zarządzaj łowiskiem".
 *
 * ⚠️ Tabela i formularz DELEGUJĄ do `PositionResource`, zamiast powielać definicje
 * kolumn i pól. Dzięki temu samodzielna strona listy (`/owner/positions?fishery=…`)
 * i ta zakładka pokazują dokładnie to samo — jedna zmiana, jedno miejsce.
 */
class PositionsRelationManager extends RelationManager
{
    protected static string $relationship = 'positions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Positions');
    }

    /**
     * ⚠️ `FisheryResource::getRelations()` obowiązuje WSZYSTKIE strony zasobu, więc bez
     * tego filtra listy zasobów podrzędnych doklejają się także do formularza edycji
     * łowiska. Zakładki należą wyłącznie do huba (zadanie 012).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ManageFishery::class;
    }

    /**
     * Plakietka na zakładce liczy wyłącznie pozycje **aktywne** — tak samo jak
     * licznik, który hub pokazywał przed przejściem na RelationManagery.
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        assert($ownerRecord instanceof Fishery);

        $count = $ownerRecord->positions()->isActive()->count();

        // Pusta zakladka nie dostaje plakietki - "0" niesie tyle samo co jej brak.
        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return PositionResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return PositionResource::table($table)
            // ⚠️ RelationManager jedzie po relacji `ownerRecord`, więc NIE przechodzi
            // przez `PositionResource::getEloquentQuery()` i nie dziedziczy stamtąd eager-loadu.
            // Bez tego `visible()` akcji edycji pyta politykę per wiersz, a ta sięga
            // po `$record->fishery` — klasyczny N+1 w tabeli nad relacją.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fishery'))
            ->headerActions([
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', Position::class))
                    ->url(fn (): string => PositionResource::getUrl('create', [
                        'fishery' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            // ⚠️ `recordActions()` z zasobu MUSI zostać nadpisane. Zasób daje tu
            // `EditAction`, a `RelationManager::isReadOnly()` jest prawdą na stronie
            // `ViewRecord` (czyli w naszym hubie) i odmawia **po klasie akcji**:
            // `CreateAction`, `EditAction`, `DeleteAction`… Efekt jest cichy — akcja
            // znika z HTML-a bez błędu, a z zakładki nie da się wejść w edycję.
            // Zwykłej `Action` ta lista nie obejmuje, więc link działa (zadanie 012).
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (Position $record): bool => Gate::allows('update', $record))
                    ->url(fn (Position $record): string => PositionResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ]);
    }
}
