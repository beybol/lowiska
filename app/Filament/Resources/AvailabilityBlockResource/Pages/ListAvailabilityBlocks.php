<?php

namespace App\Filament\Resources\AvailabilityBlockResource\Pages;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAvailabilityBlocks extends ListRecords
{
    protected static string $resource = AvailabilityBlockResource::class;

    /** @var array<string, array<string, string>> */
    protected $queryString = [
        'fisheryId' => ['as' => 'fishery'],
    ];

    public ?int $fisheryId = null;

    public function mount(): void
    {
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

    /**
     * ⚠️ Bramka biegnie tutaj, nie tylko w `mount()` — `$fisheryId` jest publiczną
     * właściwością wiązaną z query stringiem, więc kolejne żądanie Livewire może
     * przynieść inną wartość albo `null` (`autoryzacja.md` §4, warstwa 2).
     */
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->where(
            'fishery_id',
            Helper::assertFisheryAccessOrAbort($this->fisheryId),
        );
    }

    public function getBreadcrumbs(): array
    {
        return Helper::fisheryBreadcrumbs($this->fisheryId, __('Availability blocks'));
    }

    public function getTitle(): string
    {
        return Helper::getFisheryTitle($this->fisheryId, 'Availability blocks');
    }
}
