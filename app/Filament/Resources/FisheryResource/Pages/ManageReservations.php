<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\ReservationResource;
use App\Models\Fishery;
use App\Models\Reservation;
use App\Services\FisheryNavigation;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Rezerwacje jednego łowiska (ekran B) — pozycja SUB-NAWIGACJI rekordu, zaraz za „Kalendarzem"
 * (zadanie 041).
 *
 * ⚠️ Tabela DELEGUJE do `ReservationResource::table()`; strona dokłada zakładki, eager-load i przycisk
 * „Nowa rezerwacja" prowadzący na PEŁNĄ stronę formularza (C). Krótkie akcje (edycja, anulacja,
 * potwierdzenie, płatność) są modalami — świadome odstępstwo opisane w `ReservationActions`.
 */
class ManageReservations extends ManageRelatedRecords
{
    protected static string $resource = FisheryResource::class;

    protected static string $relationship = 'reservations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    public static function getNavigationLabel(): string
    {
        return __('Reservations');
    }

    public function getTitle(): string
    {
        return __('Reservations').': '.$this->fishery()->name;
    }

    private function fishery(): Fishery
    {
        /** @var Fishery $fishery */
        $fishery = $this->getOwnerRecord();

        return $fishery;
    }

    public function getBreadcrumb(): string
    {
        return __('Reservations');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->getKey(), __('Reservations'));
    }

    /**
     * Plakietka: niepotwierdzone wygasające w ciągu 24 h + „Do kontaktu" (makiety, ekran B).
     *
     * ⚠️ Liczona z rekordu przekazanego przez sub-nawigację, nie z trasy (`panel-wlasciciela.md` §2).
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
                $item->badge(static::badgeFor($fishery), 'warning');
            }
        }

        return $items;
    }

    public static function badgeFor(Fishery $fishery): ?string
    {
        $count = $fishery->reservations()
            ->where(function (Builder $query): void {
                /** @var Builder<Reservation> $query */
                $query->where(function (Builder $q): void {
                    /** @var Builder<Reservation> $q */
                    $q->expiringWithin(24);
                })->orWhere(function (Builder $q): void {
                    /** @var Builder<Reservation> $q */
                    $q->toContact();
                });
            })
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        return [
            'upcoming' => Tab::make(__('Current and upcoming'))
                ->modifyQueryUsing(function (Builder $query) use ($now): Builder {
                    /** @var Builder<Reservation> $query */
                    return $query->active()->where('ends_at', '>', $now);
                }),
            'to_contact' => Tab::make(__('To contact'))
                ->badge(fn (): ?int => ($count = $this->fishery()->reservations()->toContact()->count()) > 0 ? $count : null)
                ->modifyQueryUsing(function (Builder $query): Builder {
                    /** @var Builder<Reservation> $query */
                    return $query->toContact();
                }),
            'all' => Tab::make(__('All')),
        ];
    }

    public function table(Table $table): Table
    {
        return ReservationResource::table($table)
            // ⚠️ Strona jedzie po relacji `ownerRecord`, więc nie dziedziczy eager-loadu zasobu.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(ReservationResource::eagerLoads()))
            ->headerActions([
                Action::make('create')
                    ->label(__('New reservation'))
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Gate::allows('create', Reservation::class))
                    ->url(fn (): string => ReservationResource::getUrl('create', [
                        'fishery' => $this->fishery()->getKey(),
                    ])),
            ]);
    }
}
