<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Helpers\Helper;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Additional services'),
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
        return Helper::fisheryHubUrl($fisheryId, AdditionalServicesRelationManager::class)
            ?? AdditionalServiceResource::getUrl('index', ['fishery' => $fisheryId]);
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
