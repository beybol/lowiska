<?php

namespace App\Filament\Resources\FisheryTypeResource\Pages;

use App\Filament\Resources\FisheryTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFisheryType extends CreateRecord
{
    protected static string $resource = FisheryTypeResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another fishery type')),
            $this->getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create fishery type');
    }

    public function getRedirectUrl(): string
    {
        return FisheryTypeResource::getUrl('index');
    }
}
