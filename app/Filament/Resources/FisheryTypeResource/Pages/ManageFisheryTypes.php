<?php

namespace App\Filament\Resources\FisheryTypeResource\Pages;

use App\Filament\Resources\FisheryTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFisheryTypes extends ManageRecords
{
    protected static string $resource = FisheryTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
