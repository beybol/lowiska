<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected array $additionalServicesToSync = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->additionalServicesToSync = Helper::extractAdditionalServices($data);

        return $data;
    }

    protected function afterCreate(): void
    {
        Helper::syncAdditionalServices($this->record, $this->additionalServicesToSync);
    }

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create position');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            PositionResource::getUrl('index', ['fishery' => $fisheryId]) => __('Positions'),
            __('Create'),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another position')),
            $this->getCancelFormAction(),
        ];
    }
}
