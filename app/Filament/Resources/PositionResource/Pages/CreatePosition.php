<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\FisheryResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected array $additionalServicesToSync = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->additionalServicesToSync = Helper::extractAdditionalServices($data);

        return Helper::forceVerifiedFishery($data);
    }

    protected function afterCreate(): void
    {
        Helper::syncAdditionalServices($this->record, $this->additionalServicesToSync);
    }

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create position');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Positions'),
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
            PositionResource::class,
            PositionsRelationManager::class,
            $fisheryId,
        );
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another position')),
            $this->getCancelFormAction(),
        ];
    }
}
