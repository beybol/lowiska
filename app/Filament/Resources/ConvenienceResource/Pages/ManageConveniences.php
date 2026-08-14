<?php

namespace App\Filament\Resources\ConvenienceResource\Pages;

use App\Filament\Resources\ConvenienceResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageConveniences extends ManageRecords
{
    protected static string $resource = ConvenienceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
