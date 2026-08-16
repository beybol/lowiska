<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AdditionalServicesRelationManager;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreateAdditionalService extends CreateRecord
{
    protected static string $resource = AdditionalServiceResource::class;

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create additional service');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another additional service')),
            $this->getCancelFormAction(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Additional services'),
            self::sectionUrl($fisheryId),
            __('Create'),
        );
    }

    public function getRedirectUrl(): string
    {
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

        return self::sectionUrl($fisheryId);
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
}
