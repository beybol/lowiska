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

        // ⚠️ Bramka MUSI działać także przy edycji, nie tylko przy tworzeniu.
        // `mount()` sprawdza łowisko rekordu SPRZED zmiany, a `fishery_id` jest
        // w formularzu polem `Hidden` — bez tego dało się przenieść własny rekord
        // pod cudze łowisko, podmieniając wartość w żądaniu zapisu.
        return Helper::forceVerifiedFishery($data);
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

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            PositionResource::class,
            PositionsRelationManager::class,
            $fisheryId,
        );
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
