<?php

namespace App\Filament\Resources\FishingMethodResource\Pages;

use App\Filament\Resources\FishingMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFishingMethod extends EditRecord
{
    protected static string $resource = FishingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit fishing method');
    }
}
