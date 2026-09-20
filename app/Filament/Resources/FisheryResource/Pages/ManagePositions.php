<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\PositionResource;
use App\Models\Fishery;
use App\Models\Position;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Stanowiska jednego łowiska — pozycja SUB-NAWIGACJI rekordu.
 *
 * ⚠️ Tabela i formularz DELEGUJĄ do `PositionResource`, zamiast powielać definicje
 * kolumn i pól. Jedna zmiana, jedno miejsce.
 *
 * ⚠️ Strona zastąpiła RelationManagera (ADR-006, aktualizacja z zadania 016): ekrany
 * jednego łowiska — listy i ustawienia — są stronami zasobu i żyją w jednej nawigacji.
 */
class ManagePositions extends ManageRelatedRecords
{
    protected static string $resource = FisheryResource::class;

    protected static string $relationship = 'positions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    public static function getNavigationLabel(): string
    {
        return __('Positions');
    }

    public function getTitle(): string
    {
        return __('Positions');
    }

    public function getBreadcrumb(): string
    {
        return __('Positions');
    }

    /**
     * Plakietka liczy stanowiska BĘDĄCE W SPRZEDAŻY (`status = available`).
     * ⚠️ To stan własny stanowiska, nie dostępność w terminie — tę składa
     * `PositionAvailability`, więc plakietka celowo jej nie odzwierciedla.
     *
     * ⚠️ Plakietka liczy się z rekordu PRZEKAZANEGO przez sub-nawigację, nie z trasy.
     * `Page::getNavigationBadge()` nie dostaje żadnych parametrów, więc rekord trzeba
     * przechwycić tutaj — inaczej jedynym źródłem byłby `request()->route()`, czego
     * nie da się ani przetestować, ani wywołać poza żądaniem.
     *
     * @param  array<string, mixed>  $urlParameters
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(array $urlParameters = []): array
    {
        $items = parent::getNavigationItems($urlParameters);
        $fishery = $urlParameters['record'] ?? null;

        if ($fishery instanceof Fishery) {
            foreach ($items as $item) {
                $item->badge(static::badgeFor($fishery));
            }
        }

        return $items;
    }

    public static function badgeFor(Fishery $fishery): ?string
    {
        $count = $fishery->positions()->available()->count();

        // Pusta sekcja nie dostaje plakietki — „0" niesie tyle samo co jej brak.
        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return PositionResource::table($table)
            // ⚠️ Strona jedzie po relacji `ownerRecord`, więc NIE przechodzi przez
            // `getEloquentQuery()` zasobu i nie dziedziczy stamtąd eager-loadu. Bez tego
            // `visible()` akcji pyta politykę per wiersz, a ta sięga po `$record->fishery`.
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
            // ⚠️ Zwykłe `Action` z adresem pełnej strony, nie akcje CRUD-owe z modalami.
            // Pełne strony niosą bramkę dostępu do łowiska, wymuszenie `fishery_id` przy
            // zapisie i własne przekierowania — modal omijałby te trzy warstwy.
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
