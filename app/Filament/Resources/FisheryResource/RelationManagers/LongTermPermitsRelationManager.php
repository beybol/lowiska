<?php

namespace App\Filament\Resources\FisheryResource\RelationManagers;

use App\Filament\Resources\FisheryResource\Pages\ManageFishery;
use App\Filament\Resources\LongTermPermitResource;
use App\Models\Fishery;
use App\Models\LongTermPermit;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Pozwolenia długoterminowe łowiska — lista widoczna wprost w zakładce huba „Zarządzaj łowiskiem".
 *
 * ⚠️ Tabela i formularz DELEGUJĄ do `LongTermPermitResource`, zamiast powielać definicje
 * kolumn i pól. Dzięki temu samodzielna strona listy (`/owner/…?fishery=…`)
 * i ta zakładka pokazują dokładnie to samo — jedna zmiana, jedno miejsce.
 */
class LongTermPermitsRelationManager extends RelationManager
{
    protected static string $relationship = 'longTermPermits';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Long term permits');
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
     * Plakietka na zakładce liczy wyłącznie pozwolenia **aktywne** — tak samo jak
     * licznik, który hub pokazywał przed przejściem na RelationManagery.
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        assert($ownerRecord instanceof Fishery);

        $count = $ownerRecord->longTermPermits()->isActive()->count();

        // Pusta zakladka nie dostaje plakietki - "0" niesie tyle samo co jej brak.
        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return LongTermPermitResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return LongTermPermitResource::table($table)
            // ⚠️ RelationManager jedzie po relacji `ownerRecord`, więc NIE przechodzi
            // przez `LongTermPermitResource::getEloquentQuery()` i nie dziedziczy stamtąd eager-loadu.
            // Bez tego `visible()` akcji edycji pyta politykę per wiersz, a ta sięga
            // po `$record->fishery` — klasyczny N+1 w tabeli nad relacją.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fishery'))
            ->headerActions([
                // ⚠️ Zwykłe `Action`, nie `CreateAction`/`EditAction` — patrz komentarz
                // w `PositionsRelationManager` (zadanie 012).
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', LongTermPermit::class))
                    ->url(fn (): string => LongTermPermitResource::getUrl('create', [
                        'fishery' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (LongTermPermit $record): bool => Gate::allows('update', $record))
                    ->url(fn (LongTermPermit $record): string => LongTermPermitResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ]);
    }
}
