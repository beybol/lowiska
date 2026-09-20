<?php

namespace App\Filament\Resources\AvailabilityBlockResource\Pages;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAvailabilityBlocks;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
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
        // ⚠️ Kolejność: NAJPIERW weryfikacja łowiska, potem etykieta. `withSelectionLabel()`
        // wyszukuje grupę po `fishery_id`, więc musi dostać wartość już przepuszczoną
        // przez bramkę — odwrotne zagnieżdżenie liczyło etykietę z danych od klienta
        // i było bezpieczne wyłącznie dlatego, że bramka przerywa przez `abort()`.
        return AvailabilityBlockResource::withSelectionLabel(FisheryAccess::forceVerifiedFishery($data));
    }

    public function mount(): void
    {
        FisheryAccess::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create availability block');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return FisheryNavigation::fisheryBreadcrumbs(
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
        return FisheryNavigation::fisherySectionUrl(
            AvailabilityBlockResource::class,
            ManageAvailabilityBlocks::class,
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
