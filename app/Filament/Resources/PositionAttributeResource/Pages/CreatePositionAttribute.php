<?php

namespace App\Filament\Resources\PositionAttributeResource\Pages;

use App\Filament\Resources\PositionAttributeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePositionAttribute extends CreateRecord
{
    protected static string $resource = PositionAttributeResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another position attribute')),
            $this->getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create position attribute');
    }

    /**
     * Standardowy CRUD wraca na listę, nie na widok edycji (zadanie 011,
     * `docs/conventions/panel-admina.md` §4).
     */
    public function getRedirectUrl(): string
    {
        return PositionAttributeResource::getUrl('index');
    }
}
