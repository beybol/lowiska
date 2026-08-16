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

        return $data;
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
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

        return self::sectionUrl($fisheryId);
    }

    /**
     * Po zapisie i z okruszków wracamy na listę **w zakładce huba**, nie na samotną
     * stronę listy — ta druga wypada poza kontekst łowiska (zadanie 012).
     */
    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisheryHubUrl($fisheryId, PositionsRelationManager::class)
            ?? PositionResource::getUrl('index', ['fishery' => $fisheryId]);
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
