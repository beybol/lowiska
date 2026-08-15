<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\LongTermPermitResource;
use Filament\Resources\Pages\ViewRecord;

class ManageFishery extends ViewRecord
{
    protected static string $resource = FisheryResource::class;

    protected string $view = 'filament.resources.fisheries.pages.manage';

    public function getTitle(): string
    {
        return __('Manage fishery').' '.$this->getRecord()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Manage');
    }

    public function getViewData(): array
    {
        $fishery = $this->getRecord();

        return [
            'longTermPermitsCount' => $fishery
                ->longTermPermits()
                ->isActive()
                ->count(),
            'additionalServicesCount' => $fishery
                ->additionalServices()
                ->isActive()
                ->count(),
            'positionsCount' => $fishery
                ->positions()
                ->isActive()
                ->count(),
            'fisheryId' => $fishery->id,
            'longTermPermitsListUrl' => LongTermPermitResource::getUrl('index', ['fishery' => $fishery->id]),
            'longTermPermitsCreateUrl' => LongTermPermitResource::getUrl('create', ['fishery' => $fishery->id]),
        ];
    }
}
