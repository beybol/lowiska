<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource;
use App\Models\AdditionalService;
use App\Models\Fishery;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Usługi dodatkowe jednego łowiska — pozycja SUB-NAWIGACJI rekordu.
 *
 * ⚠️ Tabela i formularz DELEGUJĄ do `AdditionalServiceResource`, zamiast powielać definicje
 * kolumn i pól. Jedna zmiana, jedno miejsce.
 *
 * ⚠️ Strona zastąpiła RelationManagera (ADR-006, aktualizacja z zadania 016): ekrany
 * jednego łowiska — listy i ustawienia — są stronami zasobu i żyją w jednej nawigacji.
 */
class ManageAdditionalServices extends ManageRelatedRecords
{
    protected static string $resource = FisheryResource::class;

    protected static string $relationship = 'additionalServices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    public static function getNavigationLabel(): string
    {
        return __('Additional services');
    }

    public function getTitle(): string
    {
        return __('Additional services');
    }

    public function getBreadcrumb(): string
    {
        return __('Additional services');
    }

    /**
     * Plakietka liczy usługi aktywne.
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
        $count = $fishery->additionalServices()->isActive()->count();

        // Pusta sekcja nie dostaje plakietki — „0" niesie tyle samo co jej brak.
        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return AdditionalServiceResource::table($table)
            // ⚠️ Strona jedzie po relacji `ownerRecord`, więc NIE przechodzi przez
            // `getEloquentQuery()` zasobu i nie dziedziczy stamtąd eager-loadu. Bez tego
            // `visible()` akcji pyta politykę per wiersz, a ta sięga po `$record->fishery`.
            // Waluta i licznik przypięć — kolumny ceny i zasięgu czytają je na każdym wierszu.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fishery.currency')->withCount('positions'))
            ->headerActions([
                Action::make('create')
                    ->label(__('Create'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', AdditionalService::class))
                    ->url(fn (): string => AdditionalServiceResource::getUrl('create', [
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
                    ->visible(fn (AdditionalService $record): bool => Gate::allows('update', $record))
                    ->url(fn (AdditionalService $record): string => AdditionalServiceResource::getUrl('edit', [
                        'record' => $record,
                    ])),
            ]);
    }
}
