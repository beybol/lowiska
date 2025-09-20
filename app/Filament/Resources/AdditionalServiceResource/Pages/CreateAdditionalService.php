<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Helpers\Helper;

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
            AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]) 
                => __('Additional services'),
            __('Create'),
        ];
    }
}
