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
        return __('Manage fishery') . ' ' . $this->getRecord()->name;
    }
    
    public function getBreadcrumb(): string
    {
        return __('Manage');
    }

    public function getViewData(): array
    {
        $fishery = $this->getRecord();
        
        return [
            'longTermPermitsCount' => $fishery->longTermPermits()->count(),
            'additionalServicesCount' => $fishery->additionalServices()->count(),
            'positionsCount' => $fishery->positions()->count(),
            'fisheryId' => $fishery->id,
        ];
    }
}
