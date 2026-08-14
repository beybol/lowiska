<?php
namespace App\Filament\Resources\FishResource\Pages;

use App\Filament\Resources\FishResource;
use Filament\Resources\Pages\EditRecord;

class EditFish extends EditRecord
{
    protected static string $resource = FishResource::class;

    public function getTitle(): string
    {
        return __('Edit fish');
    }
}