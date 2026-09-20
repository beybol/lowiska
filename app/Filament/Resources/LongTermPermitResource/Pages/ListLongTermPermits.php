<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLongTermPermits extends ListRecords
{
    protected static string $resource = LongTermPermitResource::class;

    protected $queryString = [
        'fisheryId' => ['as' => 'fishery'],
    ];

    public ?int $fisheryId = null;

    public function mount(): void
    {
        // ⚠️ Przypisanie idzie PO bramce, bo `$fisheryId` jest typowane `?int`,
        // a `request()->get()` daje string — `?fishery=abc` wywalało `TypeError`
        // (500) jeszcze zanim bramka zdążyła zwrócić 404. Bramka normalizuje
        // wartość i zwraca zweryfikowany `int`.
        $this->fisheryId = FisheryAccess::assertFisheryAccessOrAbort(request()->get('fishery'));
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return FisheryNavigation::getListHeaderActionsForFishery(static::$resource, $this->fisheryId);
    }

    public function getTitle(): string
    {
        return FisheryNavigation::getFisheryTitle($this->fisheryId, 'Long term permits');
    }

    protected function getTableQuery(): ?Builder
    {
        // ⚠️ Bramka na każdym żądaniu, nie tylko w `mount()` — patrz komentarz
        // w `ListPositions::getTableQuery()`.
        $query = parent::getTableQuery();

        return $query->where(
            'fishery_id',
            FisheryAccess::assertFisheryAccessOrAbort($this->fisheryId),
        );
    }

    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs(
            $this->fisheryId,
            __('Long term permits'),
        );
    }
}
