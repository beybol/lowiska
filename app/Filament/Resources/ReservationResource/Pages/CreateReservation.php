<?php

namespace App\Filament\Resources\ReservationResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\ReservationFormSchema;
use App\Filament\Resources\ReservationResource\ReservationFormSubmission;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use App\Services\ReservationBooking;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Formularz rezerwacji (ekran C) — PEŁNA strona z `Grid` + `Section` (zadanie 041).
 *
 * ⚠️ **Zapis idzie przez `ReservationBooking`, nie przez `Model::create()`** — `handleRecordCreation()`
 * woła serwis, a odmowa (zajęte, brak powodu wyjątku, limit usług) wraca jako komunikat i zostawia
 * formularz otwarty. Pola i podgląd oferty buduje `ReservationFormSchema` (wspólny z pełną edycją).
 *
 * Parametry z adresu (`position`, `from`, `nights`) wypełniają formularz — wejście z kalendarza (042).
 * Stanowisko z adresu przechodzi przez zawężenie do łowiska; obce id po prostu się nie wypełnia.
 */
class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    public function mount(): void
    {
        FisheryAccess::assertFisheryAccessOrAbort();
        parent::mount();

        $fisheryId = (int) request()->get('fishery');
        $position = request()->get('position');
        $prefill = array_filter([
            'position_id' => is_numeric($position) && Position::query()->where('fishery_id', $fisheryId)->whereKey($position)->exists()
                ? (int) $position
                : null,
            'starts_on' => self::validDate(request()->get('from')),
            'nights' => is_numeric(request()->get('nights')) ? max(1, min(365, (int) request()->get('nights'))) : null,
        ], fn ($value): bool => $value !== null);

        if ($prefill !== []) {
            $this->form->fill(array_merge($this->form->getRawState(), $prefill));
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(ReservationFormSchema::full());
    }

    /**
     * ⚠️ Zapis przez serwis — odmowa wraca komunikatem i zatrzymuje zapis (formularz zostaje).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data = FisheryAccess::forceVerifiedFishery($data);
        $fishery = Fishery::query()->findOrFail($data['fishery_id']);
        $outcome = ReservationFormSubmission::book($fishery, $data);

        if (! $outcome->succeeded || $outcome->reservation === null) {
            Notification::make()->danger()->title(__('The reservation was not saved'))->body($outcome->message())->send();
            $this->halt();
        }

        return $outcome->reservation;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('The reservation was saved');
    }

    /**
     * ⚠️ Komunikat dla operatora jest TEN SAM bez względu na to, czy adres ma konto w portalu
     * (ADR-024 pkt 3) — panel nie może służyć do sprawdzania, kto jest w Fisherya.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title($this->getCreatedNotificationTitle())
            ->body(self::requestNotice($this->record instanceof Reservation ? $this->record : null));
    }

    /** Ta sama treść po zapisie z pełnego formularza i z kalendarza. */
    public static function requestNotice(?Reservation $reservation): ?string
    {
        return filled($reservation?->angler?->effectiveEmail())
            ? __('A request to confirm the reservation was sent to the angler.')
            : null;
    }

    public function getRedirectUrl(): string
    {
        // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL.
        $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

        return FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $fisheryId);
    }

    public function getTitle(): string
    {
        return __('New reservation');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return FisheryNavigation::fisheryBreadcrumbs(
            $fisheryId,
            __('Reservations'),
            FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $fisheryId),
            __('New reservation'),
        );
    }

    protected function getFormActions(): array
    {
        return [$this->getCreateFormAction()->label(__('Save the reservation')), $this->getCancelFormAction()];
    }

    private static function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
