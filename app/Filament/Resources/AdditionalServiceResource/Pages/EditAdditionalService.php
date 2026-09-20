<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ⚠️ Bramka MUSI działać także przy edycji — patrz komentarz w `EditPosition`.
        return FisheryAccess::forceVerifiedFishery($data);
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
