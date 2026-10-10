<?php

namespace App\Filament\Resources\ReservationResource\Pages;

use App\Filament\Resources\ReservationResource;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Samodzielna lista rezerwacji jednego łowiska (`?fishery=`) — cel deep-linku i fallback
 * `FisheryNavigation::fisherySectionUrl()`. Operator pracuje na `ManageReservations` (sub-nawigacja).
 */
class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    /** @var array<string, array<string, string>> */
    protected $queryString = [
        'fisheryId' => ['as' => 'fishery'],
    ];

    public ?int $fisheryId = null;

    public function mount(): void
    {
        $this->fisheryId = FisheryAccess::assertFisheryAccessOrAbort(request()->get('fishery'));
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return FisheryNavigation::getListHeaderActionsForFishery(static::$resource, $this->fisheryId);
    }

    /**
     * ⚠️ Bramka na każdym żądaniu listy, nie tylko w `mount()` (`autoryzacja.md` §4, warstwa 2).
     */
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->where(
            'fishery_id',
            FisheryAccess::assertFisheryAccessOrAbort($this->fisheryId),
        );
    }

    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fisheryId, __('Reservations'));
    }

    public function getTitle(): string
    {
        return FisheryNavigation::getFisheryTitle($this->fisheryId, 'Reservations');
    }
}
