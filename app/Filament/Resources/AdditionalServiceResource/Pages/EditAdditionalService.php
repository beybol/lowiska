<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Helpers\Helper;

class EditAdditionalService extends EditRecord
{
    protected static string $resource = AdditionalServiceResource::class;

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
        return __('Edit additional service');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->record->fishery_id ?? null;

        return [
            AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]) 
                => __('Additional services'),
            __('Edit'),
        ];
    }

    protected function getFormActions(): array
    {
        return Helper::getEditFormActionsForFishery(
            $this->record,
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        );
    }
}
