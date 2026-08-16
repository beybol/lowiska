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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Helper::forceVerifiedFishery($data);
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
        // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL — przy właścicielu
        // dwóch łowisk rekord mógł wylądować w B, a przekierowanie prowadzić do A.
        $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

        return self::sectionUrl($fisheryId);
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            AdditionalServiceResource::class,
            AdditionalServicesRelationManager::class,
            $fisheryId,
        );
    }
}
