<?php

namespace App\Filament\Resources\AvailabilityBlockResource\Pages;

use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource\Pages\ManageAvailabilityBlocks;
use App\Models\AvailabilityBlock;
use App\Services\CollisionGate;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
        // ⚠️ Kolejność: NAJPIERW weryfikacja łowiska, potem etykieta. `withSelectionLabel()`
        // wyszukuje grupę po `fishery_id`, więc musi dostać wartość już przepuszczoną
        // przez bramkę — odwrotne zagnieżdżenie liczyło etykietę z danych od klienta
        // i było bezpieczne wyłącznie dlatego, że bramka przerywa przez `abort()`.
        return AvailabilityBlockResource::withSelectionLabel(FisheryAccess::forceVerifiedFishery($data));
    }

    /**
     * ⚠️ Zmiana dat albo zbioru, która zaczyna kolidować z rezerwacjami, idzie przez ekran decyzji —
     * zapis edycji i decyzje razem (ADR-027, zadanie 044). Rezerwacje już zostawione przy tej blokadzie
     * nie wracają na listę.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $gate = (new CollisionGate)->forBlock($data, (array) ($this->data['positions'] ?? []), (int) $record->getKey());

        if ($gate['redirect'] !== null) {
            $this->redirect($gate['redirect']);
            $this->halt();
        }

        if ($gate['informational'] > 0) {
            Notification::make()->info()
                ->title(trans_choice('The suspension applies to :count reservation|The suspension applies to :count reservations', $gate['informational'], ['count' => $gate['informational']]))
                ->send();
        }

        return parent::handleRecordUpdate($record, $data);
    }

    public function mount(string|int $record): void
    {
        parent::mount($record);
        FisheryAccess::assertFisheryAccessOrAbort($this->availabilityBlock()->fishery_id);
    }

    public function getTitle(): string
    {
        return __('Edit availability block');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = $this->availabilityBlock()->fishery_id;

        return FisheryNavigation::fisheryBreadcrumbs(
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
        return FisheryNavigation::fisherySectionUrl(
            AvailabilityBlockResource::class,
            ManageAvailabilityBlocks::class,
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
        return FisheryNavigation::getEditFormActionsForFishery(
            $this->record,
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        );
    }
}
