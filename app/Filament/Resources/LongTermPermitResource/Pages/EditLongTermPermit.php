<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLongTermPermit extends EditRecord
{
    protected static string $resource = LongTermPermitResource::class;

    public function mount(string|int $record): void
    {
        parent::mount($record);
        Helper::assertFisheryAccessOrAbort($this->record->fishery_id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit long term permit');
    }

    protected function getFormActions(): array
    {
        $cancelActionModifier = Helper::getBackToFisheryManagementAction(
            $this->record->fishery_id, 
            'cancel',
        );
        
        return [
            $this->getSaveFormAction(),
            $cancelActionModifier($this->getCancelFormAction()),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

        return [
            LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]) 
                => __('Long term permits'),
            __('Edit'),
        ];
    }
}
