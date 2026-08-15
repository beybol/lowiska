<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreateAdditionalService extends CreateRecord
{
    protected static string $resource = AdditionalServiceResource::class;

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create additional service');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another additional service')),
            $this->getCancelFormAction(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]) => __('Additional services'),
            __('Create'),
        ];
    }

    public function getRedirectUrl(): string
    {
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

        return AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]);
    }
}
