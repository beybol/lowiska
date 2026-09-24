<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
use App\Models\AdditionalService;
use App\Services\AdditionalServiceSync;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdditionalService extends EditRecord
{
    protected static string $resource = AdditionalServiceResource::class;

    public function mount(string|int $record): void
    {
        parent::mount($record);
        FisheryAccess::assertFisheryAccessOrAbort($this->record->fishery_id);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit additional service');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->record->fishery_id ?? null;

        return FisheryNavigation::fisheryBreadcrumbs(
            $fisheryId,
            __('Additional services'),
            self::sectionUrl($fisheryId),
            __('Edit'),
        );
    }

    /**
     * Wymagane cechy nie są kolumną — patrz `CreateAdditionalService::$requiredAttributeIds`.
     *
     * @var array<int, mixed>
     */
    protected array $requiredAttributeIds = [];

    /**
     * ⚠️ Formularz pokazuje wyłącznie wymogi cech ISTNIEJĄCYCH w słowniku; wymóg cechy usuniętej
     * miękko zostaje w bazie i bramka zapisu go nie kasuje.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['required_attribute_ids'] = $this->record instanceof AdditionalService
            ? $this->record->requiredAttributes()
                ->pluck('position_attributes.id')
                ->map(fn ($id): string => (string) $id)
                ->all()
            : [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->requiredAttributeIds = (array) ($data['required_attribute_ids'] ?? []);
        unset($data['required_attribute_ids']);

        // ⚠️ Bramka MUSI działać także przy edycji — patrz komentarz w `EditPosition`.
        return FisheryAccess::forceVerifiedFishery($data);
    }

    protected function afterSave(): void
    {
        if ($this->record instanceof AdditionalService) {
            AdditionalServiceSync::syncRequiredAttributes($this->record, $this->requiredAttributeIds);
        }
    }

    public function getRedirectUrl(): string
    {
        return self::sectionUrl($this->record->fishery_id ?? null);
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return FisheryNavigation::fisherySectionUrl(
            AdditionalServiceResource::class,
            ManageAdditionalServices::class,
            $fisheryId,
        );
    }

    protected function getFormActions(): array
    {
        return FisheryNavigation::getEditFormActionsForFishery(
            $this->record,
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        );
    }
}
