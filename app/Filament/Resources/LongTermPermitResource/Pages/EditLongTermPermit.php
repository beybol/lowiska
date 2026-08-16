<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\LongTermPermitResource;
use App\Helpers\Helper;
use Filament\Actions\DeleteAction;
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
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit long term permit');
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
            __('Long term permits'),
            self::sectionUrl($fisheryId),
            __('Edit'),
        );
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ⚠️ Bramka MUSI działać także przy edycji — patrz komentarz w `EditPosition`.
        return Helper::forceVerifiedFishery($data);
    }

    public function getRedirectUrl(): string
    {
        return self::sectionUrl($this->record->fishery_id ?? null);
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            LongTermPermitResource::class,
            LongTermPermitsRelationManager::class,
            $fisheryId,
        );
    }
}
