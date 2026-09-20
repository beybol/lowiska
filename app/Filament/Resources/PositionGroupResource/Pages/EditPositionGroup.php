<?php

namespace App\Filament\Resources\PositionGroupResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManagePositionGroups;
use App\Filament\Resources\PositionGroupResource;
use App\Helpers\Helper;
use App\Models\PositionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPositionGroup extends EditRecord
{
    protected static string $resource = PositionGroupResource::class;

    /**
     * ⚠️ Bramka MUSI działać także przy edycji. `mount()` sprawdza łowisko rekordu
     * SPRZED zmiany, a `fishery_id` jest w formularzu polem `Hidden` — bez tego dało
     * się przenieść własną grupę pod cudze łowisko (`autoryzacja.md` §4, warstwa 3).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Helper::forceVerifiedFishery($data);
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);
        Helper::assertFisheryAccessOrAbort($this->positionGroup()->fishery_id);
    }

    public function getTitle(): string
    {
        return __('Edit position group');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->positionGroup()->fishery_id;

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Position groups'),
            self::sectionUrl($fisheryId),
            __('Edit'),
        );
    }

    public function getRedirectUrl(): string
    {
        return self::sectionUrl($this->positionGroup()->fishery_id);
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez zawężenia każde sięgnięcie po pole
     * grupy jest dla analizy statycznej dostępem do nieznanej właściwości — ten sam
     * zabieg co w `ManageFishery` (zadanie 012).
     */
    private function positionGroup(): PositionGroup
    {
        $record = $this->getRecord();
        assert($record instanceof PositionGroup);

        return $record;
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            PositionGroupResource::class,
            ManagePositionGroups::class,
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
