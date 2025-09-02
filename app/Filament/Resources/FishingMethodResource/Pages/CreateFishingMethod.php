<?php

namespace App\Filament\Resources\FishingMethodResource\Pages;

use App\Filament\Resources\FishingMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFishingMethod extends CreateRecord
{
    protected static string $resource = FishingMethodResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another fishing method')),
            $this->getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create fishing method');
    }
}