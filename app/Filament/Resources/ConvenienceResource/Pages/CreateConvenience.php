<?php

namespace App\Filament\Resources\ConvenienceResource\Pages;

use App\Filament\Resources\ConvenienceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConvenience extends CreateRecord
{
    protected static string $resource = ConvenienceResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another convenience')),
            $this->getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create convenience');
    }
}
