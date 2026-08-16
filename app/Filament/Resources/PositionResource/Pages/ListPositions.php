<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPositions extends ListRecords
{
    protected static string $resource = PositionResource::class;

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
        $this->fisheryId = Helper::assertFisheryAccessOrAbort(request()->get('fishery'));
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return Helper::getListHeaderActionsForFishery(
            static::$resource,
            $this->fisheryId,
        );
    }

    protected function getTableQuery(): Builder
    {
        // ⚠️ Bramka biegnie tu, a nie tylko w `mount()`. `$fisheryId` jest publiczną
        // właściwością komponentu wiązaną z query stringiem, więc kolejne żądanie
        // Livewire może przynieść inną wartość — albo `null`, przy którym dawny
        // warunkowy filtr nie dokładał NICZEGO i lista pokazywała cudze rekordy.
        $query = parent::getTableQuery();

        return $query->where(
            'fishery_id',
            Helper::assertFisheryAccessOrAbort($this->fisheryId),
        );
    }

    public function getBreadcrumbs(): array
    {
        return Helper::fisheryBreadcrumbs(
            $this->fisheryId,
            __('Positions'),
        );
    }

    public function getTitle(): string
    {
        return Helper::getFisheryTitle($this->fisheryId, 'Positions');
    }
}
