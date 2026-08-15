<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAdditionalServices extends ListRecords
{
    protected static string $resource = AdditionalServiceResource::class;

    protected $queryString = ['fisheryId' => ['as' => 'fishery']];

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
        return Helper::getFisheryTitle($this->fisheryId, 'Additional services');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]) => __('Additional services'),
            __('List'),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if ($this->fisheryId) {
            $query->where('fishery_id', $this->fisheryId);
        }

        return $query;
    }

    public function getRedirectUrl(): string
    {
        $fisheryId = request()->get('fishery')
            ?? $this->record->fishery_id
            ?? null;

        return static::$resource::getUrl('index', ['fishery' => $fisheryId]);
    }
}
