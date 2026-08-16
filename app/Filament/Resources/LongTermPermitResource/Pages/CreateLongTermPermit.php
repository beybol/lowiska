<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\FisheryResource\RelationManagers\LongTermPermitsRelationManager;
use App\Filament\Resources\LongTermPermitResource;
use App\Helpers\Helper;
use Filament\Resources\Pages\CreateRecord;

class CreateLongTermPermit extends CreateRecord
{
    protected static string $resource = LongTermPermitResource::class;

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create long term permit');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Long term permits'),
            self::sectionUrl($fisheryId),
            __('Create'),
        );
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // ⚠️ Wcześniej nadpisanie było warunkowe (`if ($fisheryId)`), więc w żądaniu
        // zapisu — które nie niesie `?fishery` — wracała nietknięta wartość z pola
        // `Hidden`, czyli od klienta. Teraz każda wartość przechodzi przez bramkę.
        return Helper::forceVerifiedFishery($data);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another long term permit')),
            $this->getCancelFormAction(),
        ];
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
            LongTermPermitResource::class,
            LongTermPermitsRelationManager::class,
            $fisheryId,
        );
    }
}
