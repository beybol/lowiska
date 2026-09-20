<?php

namespace App\Filament\Resources\AvailabilityBlockResource\Pages;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AvailabilityBlocksRelationManager;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreateAvailabilityBlock extends CreateRecord
{
    protected static string $resource = AvailabilityBlockResource::class;

    /**
     * ⚠️ Bramka przy zapisie jest obowiązkowa: `fishery_id` przychodzi z pola
     * `Hidden`, czyli od klienta (`autoryzacja.md` §4, warstwa 3).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Helper::forceVerifiedFishery(AvailabilityBlockResource::withSelectionLabel($data));
    }

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create availability block');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Availability blocks'),
            self::sectionUrl($fisheryId),
            __('Create'),
        );
    }

    public function getRedirectUrl(): string
    {
        // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL.
        $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

        return self::sectionUrl($fisheryId);
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            AvailabilityBlockResource::class,
            AvailabilityBlocksRelationManager::class,
            $fisheryId,
        );
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another availability block')),
            $this->getCancelFormAction(),
        ];
    }
}
