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
        $fisheryId = request()->get('fishery');
        if ($fisheryId) {
            $data['fishery_id'] = $fisheryId;
        }

        return $data;
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
        $fisheryId = request()->get('fishery') ?? $this->record->fishery_id ?? null;

        return self::sectionUrl($fisheryId);
    }

    /**
     * Po zapisie i z okruszków wracamy na listę **w zakładce huba**, nie na samotną
     * stronę listy — ta druga wypada poza kontekst łowiska (zadanie 012).
     */
    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisheryHubUrl($fisheryId, LongTermPermitsRelationManager::class)
            ?? LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]);
    }
}
