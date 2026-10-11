<?php

namespace App\Filament\Resources\ReservationResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\ReservationFormSchema;
use App\Filament\Resources\ReservationResource\ReservationFormSubmission;
use App\Models\Reservation;
use App\Services\FisheryNavigation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Pełna edycja rezerwacji (zadanie 047, C1) — ten sam formularz co C (`ReservationFormSchema::full()`),
 * wypełniony danymi rezerwacji: usługi, cena uzgodniona i zapisane wyjątki są widoczne.
 *
 * ⚠️ **Zapis przez `ReservationBooking`** (`ReservationFormSubmission::update()`): zmiana samego kontaktu
 * albo notatki nie przelicza warunków; zmiana terminu, stanowiska, składu, usług albo ceny idzie przez
 * `rebook()` z powodem, a wyjątki, polityka zwrotu i dokumenty zostają zamrożone (C2).
 *
 * ⚠️ Rekord wiąże zawężone zapytanie zasobu (cudza rezerwacja = 404), `EditRecord::authorizeAccess()`
 * pyta `update` polityki, a łowisko pól reaktywnych pochodzi z rekordu, nie z formularza (D1).
 */
class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Rezerwacja anulowana albo wygasła jest historią — nie ma czego edytować.
        abort_unless($this->reservation()->isActive(), 404);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(ReservationFormSchema::full($this->reservation()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ReservationFormSchema::fullState($this->reservation());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Reservation);
        $this->authorizeAccess();
        $outcome = ReservationFormSubmission::update($record, $data);

        if (! $outcome->succeeded || $outcome->reservation === null) {
            Notification::make()->danger()->title(__('The reservation was not changed'))->body($outcome->message())->send();
            $this->halt();
        }

        return $outcome->reservation;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('The reservation was changed');
    }

    protected function getRedirectUrl(): string
    {
        return ReservationResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return __('Edit reservation').' #'.$this->getRecord()->getKey();
    }

    public function getBreadcrumbs(): array
    {
        $record = $this->reservation();

        return FisheryNavigation::fisheryBreadcrumbs(
            $record->fishery_id,
            __('Reservations'),
            FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $record->fishery_id),
            '#'.$record->getKey(),
        );
    }

    private function reservation(): Reservation
    {
        $record = $this->getRecord();
        assert($record instanceof Reservation);

        return $record;
    }
}
