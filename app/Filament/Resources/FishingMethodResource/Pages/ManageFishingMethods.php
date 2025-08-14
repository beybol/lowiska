<?php

namespace App\Filament\Resources\FishingMethodResource\Pages;

use App\Filament\Resources\FishingMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFishingMethods extends ManageRecords
{
    protected static string $resource = FishingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
