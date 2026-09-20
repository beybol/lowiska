<?php

namespace App\Filament\Resources\PositionGroupResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManagePositionGroups;
use App\Filament\Resources\PositionGroupResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreatePositionGroup extends CreateRecord
{
    protected static string $resource = PositionGroupResource::class;

    /**
     * ⚠️ Bramka przy zapisie jest obowiązkowa: `fishery_id` przychodzi z pola
     * `Hidden`, czyli od klienta (`autoryzacja.md` §4, warstwa 3).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Helper::forceVerifiedFishery($data);
    }

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create position group');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Position groups'),
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
            PositionGroupResource::class,
            ManagePositionGroups::class,
            $fisheryId,
        );
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another position group')),
            $this->getCancelFormAction(),
        ];
    }
}
