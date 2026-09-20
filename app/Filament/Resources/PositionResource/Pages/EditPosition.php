<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\PositionResource;
use App\Models\Position;
use App\Services\AdditionalServiceSync;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use App\Services\PositionAttributeWriter;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPosition extends EditRecord
{
    protected static string $resource = PositionResource::class;

    protected array $additionalServicesToSync = [];

    /** @var array<int|string, mixed> */
    protected array $attributesToWrite = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->additionalServicesToSync = AdditionalServiceSync::extractAdditionalServices($data);

        // ⚠️ Jak przy tworzeniu — `position_attributes` nie jest kolumną.
        $this->attributesToWrite = $data['position_attributes'] ?? [];
        unset($data['position_attributes']);

        // ⚠️ Bramka MUSI działać także przy edycji, nie tylko przy tworzeniu.
        // `mount()` sprawdza łowisko rekordu SPRZED zmiany, a `fishery_id` jest
        // w formularzu polem `Hidden` — bez tego dało się przenieść własny rekord
        // pod cudze łowisko, podmieniając wartość w żądaniu zapisu.
        return FisheryAccess::forceVerifiedFishery($data);
    }

    protected function afterSave(): void
    {
        AdditionalServiceSync::syncAdditionalServices($this->record, $this->additionalServicesToSync);

        $position = $this->record;
        assert($position instanceof Position);

        app(PositionAttributeWriter::class)->writeForPosition($position, $this->attributesToWrite);
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);
        $this->record->load('additionalServices', 'attributeValues.attribute', 'groups');
        FisheryAccess::assertFisheryAccessOrAbort($this->record->fishery_id);

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
        return FisheryNavigation::getEditFormActionsForFishery(
            $this->record,
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        );
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->record->fishery_id ?? null;

        return FisheryNavigation::fisheryBreadcrumbs(
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
        return FisheryNavigation::fisherySectionUrl(
            PositionResource::class,
            ManagePositions::class,
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
