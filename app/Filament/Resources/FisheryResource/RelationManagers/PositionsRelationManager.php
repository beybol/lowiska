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

        return (string) $ownerRecord->positions()->isActive()->count();
    }

    public function form(Schema $schema): Schema
    {
        return PositionResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return PositionResource::table($table)
            ->headerActions([
                // ⚠️ Zwykła `Action`, nie `CreateAction` — ta druga w RelationManagerze
                // przepada na własnej autoryzacji relacji i przycisk w ogóle się nie
                // renderuje. Tu i tak nie chcemy modala, tylko linku na pełną stronę
                // dodawania, która niesie własny formularz i bramkę dostępu (zadanie 012).
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', Position::class))
                    ->url(fn (): string => PositionResource::getUrl('create', [
                        'fishery' => $this->getOwnerRecord()->getKey(),
                    ])),
            ]);
    }
}
