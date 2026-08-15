<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    protected array $additionalServicesToSync = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->additionalServicesToSync = Helper::extractAdditionalServices($data);

        return $data;
    }

    protected function afterSave(): void
    {
        Helper::syncAdditionalServices($this->record, $this->additionalServicesToSync);
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);
        $this->record->load('additionalServices');
        Helper::assertFisheryAccessOrAbort($this->record->fishery_id);

        $this->form->fill(
            PositionResource::getEloquentFormData($this->record)
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
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

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->record->fishery_id ?? null;

        return [
            PositionResource::getUrl('index', ['fishery' => $fisheryId]) => __('Positions'),
            __('Edit'),
        ];
    }

    public function getFormState(): array
    {
        return PositionResource::getEloquentFormData($this->record);
    }

    public function getTitle(): string
    {
        return __('Edit position');
    }
}
