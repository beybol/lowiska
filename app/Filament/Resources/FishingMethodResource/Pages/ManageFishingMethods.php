<?php

namespace App\Filament\Resources\FishingMethodResource\Pages;

use App\Filament\Resources\FishingMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFishingMethods extends ManageRecords
{
    protected static string $resource = FishingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Fishing methods');
    }
}
