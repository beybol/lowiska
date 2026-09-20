<?php

namespace App\Filament\Resources\PositionAttributeResource\Pages;

use App\Filament\Resources\PositionAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePositionAttributes extends ManageRecords
{
    protected static string $resource = PositionAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
