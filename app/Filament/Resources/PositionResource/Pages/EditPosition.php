<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
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

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Positions'),
            self::sectionUrl($fisheryId),
            __('Edit'),
        );
    }

    public function getRedirectUrl(): string
    {
        return self::sectionUrl($this->record->fishery_id ?? null);
    }

    /**
     * Po zapisie i z okruszków wracamy na listę **w zakładce huba**, nie na samotną
     * stronę listy — ta druga wypada poza kontekst łowiska (zadanie 012).
     */
    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisheryHubUrl($fisheryId, PositionsRelationManager::class)
            ?? PositionResource::getUrl('index', ['fishery' => $fisheryId]);
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
