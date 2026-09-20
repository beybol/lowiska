<?php

namespace App\Filament\Resources\AvailabilityBlockResource\Pages;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource\RelationManagers\AvailabilityBlocksRelationManager;
use App\Helpers\Helper;
use App\Models\AvailabilityBlock;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAvailabilityBlock extends EditRecord
{
    protected static string $resource = AvailabilityBlockResource::class;

    /**
     * ⚠️ Bramka MUSI działać także przy edycji. `mount()` sprawdza łowisko rekordu
     * SPRZED zmiany, a `fishery_id` jest w formularzu polem `Hidden` — bez tego dało
     * się przenieść własną grupę pod cudze łowisko (`autoryzacja.md` §4, warstwa 3).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Helper::forceVerifiedFishery(AvailabilityBlockResource::withSelectionLabel($data));
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);
        Helper::assertFisheryAccessOrAbort($this->availabilityBlock()->fishery_id);
    }

    public function getTitle(): string
    {
        return __('Edit availability block');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->availabilityBlock()->fishery_id;

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Availability blocks'),
            self::sectionUrl($fisheryId),
            __('Edit'),
        );
    }

    public function getRedirectUrl(): string
    {
        return self::sectionUrl($this->availabilityBlock()->fishery_id);
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez zawężenia każde sięgnięcie po pole
     * grupy jest dla analizy statycznej dostępem do nieznanej właściwości — ten sam
     * zabieg co w `ManageFishery` (zadanie 012).
     */
    private function availabilityBlock(): AvailabilityBlock
    {
        $record = $this->getRecord();
        assert($record instanceof AvailabilityBlock);

        return $record;
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            AvailabilityBlockResource::class,
            AvailabilityBlocksRelationManager::class,
            $fisheryId,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return Helper::getEditFormActionsForFishery(
            $this->record,
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        );
    }
}
