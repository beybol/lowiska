<?php

namespace App\Filament\Resources\PositionAttributeResource\Pages;

use App\Filament\Resources\PositionAttributeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPositionAttribute extends EditRecord
{
    protected static string $resource = PositionAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit position attribute');
    }

    public function getRedirectUrl(): string
    {
        return PositionAttributeResource::getUrl('index');
    }
}
