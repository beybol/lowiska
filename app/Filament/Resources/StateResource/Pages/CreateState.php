<?php

namespace App\Filament\Resources\StateResource\Pages;

use App\Filament\Resources\StateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateState extends CreateRecord
{
    protected static string $resource = StateResource::class;

    protected function getFormActions(): array
    {
        return [
            parent::getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another state')),
            parent::getCancelFormAction(),
        ];
    }
}
