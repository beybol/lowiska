<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use App\Models\Fishery;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Helpers\Helper;

class CreateLongTermPermit extends CreateRecord
{
    protected static string $resource = LongTermPermitResource::class;

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {   
        return __('Create long term permit');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]) 
                => __('Long term permits'),
            __('Create'),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $fisheryId = request()->get('fishery');
        if ($fisheryId) {
            $data['fishery_id'] = $fisheryId;
        }
        
        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another long term permit')),
            $this->getCancelFormAction(),
        ];
    }

    public function getRedirectUrl(): string
    {
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;
        return LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]);
    }
}
