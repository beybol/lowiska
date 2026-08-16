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
        $this->fisheryId = request()->get('fishery');
        Helper::assertFisheryAccessOrAbort($this->fisheryId);
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
        $query = parent::getTableQuery();

        if ($this->fisheryId) {
            $query->where('fishery_id', $this->fisheryId);
        }

        return $query;
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
