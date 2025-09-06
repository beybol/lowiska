<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\FisheryResource;

class ManageFishery extends ViewRecord
{
    protected static string $resource = FisheryResource::class;
    protected static string $view = 'filament.resources.fisheries.pages.manage';
    
    public function getTitle(): string
    {
        return __('Manage') . ': ' . $this->getRecord()->name;
    }
}
