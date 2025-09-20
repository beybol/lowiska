<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Helpers\Helper;
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
        return Helper::getListHeaderActionsForFishery(static::$resource, $this->fisheryId);
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        if ($this->fisheryId) {
            $query->where('fishery_id', $this->fisheryId);
        }

        return $query;
    }
}
