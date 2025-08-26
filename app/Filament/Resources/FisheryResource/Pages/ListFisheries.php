<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFisheries extends ListRecords
{
    protected static string $resource = FisheryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
