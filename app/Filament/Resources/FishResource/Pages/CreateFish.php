<?php
namespace App\Filament\Resources\FishResource\Pages;

use App\Filament\Resources\FishResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFish extends CreateRecord
{
    protected static string $resource = FishResource::class;

    public function getTitle(): string
    {
        return __('Create fish');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another fish')),
            $this->getCancelFormAction(),
        ];
    }
}