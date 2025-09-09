<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLongTermPermit extends CreateRecord
{
    protected static string $resource = LongTermPermitResource::class;

    public function getTitle(): string
    {
        return __('Create long term permit');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another long term permit')),
            $this->getCancelFormAction(),
        ];
    }
}
