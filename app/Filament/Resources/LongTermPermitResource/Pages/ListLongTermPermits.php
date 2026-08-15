<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use App\Helpers\Helper;
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
        $this->fisheryId = request()->get('fishery');
        Helper::assertFisheryAccessOrAbort($this->fisheryId);
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return Helper::getListHeaderActionsForFishery(static::$resource, $this->fisheryId);
    }

    public function getTitle(): string
    {
        return Helper::getFisheryTitle($this->fisheryId, 'Long term permits');
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if ($this->fisheryId) {
            $query->where('fishery_id', $this->fisheryId);
        }

        return $query;
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]) => __('Long term permits'),
            __('List'),
        ];
    }
}
