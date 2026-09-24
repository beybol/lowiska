<?php

namespace App\Filament\Resources\AdditionalServiceResource\Pages;

use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAdditionalServices;
use App\Models\AdditionalService;
use App\Services\AdditionalServiceSync;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use Filament\Resources\Pages\CreateRecord;

class CreateAdditionalService extends CreateRecord
{
    protected static string $resource = AdditionalServiceResource::class;

    public function mount(): void
    {
        FisheryAccess::assertFisheryAccessOrAbort();
        parent::mount();
    }

    /**
     * Wymagane cechy nie są kolumną — zapisuje je bramka po utworzeniu rekordu, w tym samym
     * żądaniu (dlatego wystarcza właściwość chroniona).
     *
     * @var array<int, mixed>
     */
    protected array $requiredAttributeIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->requiredAttributeIds = (array) ($data['required_attribute_ids'] ?? []);
        unset($data['required_attribute_ids']);

        return FisheryAccess::forceVerifiedFishery($data);
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof AdditionalService) {
            AdditionalServiceSync::syncRequiredAttributes($this->record, $this->requiredAttributeIds);
        }
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

        return FisheryNavigation::fisheryBreadcrumbs(
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
        return FisheryNavigation::fisherySectionUrl(
            AdditionalServiceResource::class,
            ManageAdditionalServices::class,
            $fisheryId,
        );
    }
}
