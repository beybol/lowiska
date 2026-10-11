<?php

namespace App\Filament\Resources\ReservationResource;

use App\Enums\ReservationSource;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\AnglerDetails;
use App\Services\BookingOutcome;
use App\Services\BookingRequest;
use App\Services\FisheryAccess;
use App\Services\FishingDayCalendar;
use App\Services\ReservationBooking;
use App\Services\ReservationConfirmation;

/**
 * JEDNO przejście z danych formularza rezerwacji do `ReservationBooking` — dla pełnej strony C
 * (`CreateReservation`), krótkiego formularza w kalendarzu (zadanie 042) oraz szybkiej i pełnej edycji
 * (zadanie 047). Jedna interpretacja pól: stanowisko i relacja wędkarza tylko z TEGO łowiska, kanał
 * operatora/admina, termin potwierdzenia z ustawień łowiska (najpóźniej początek pobytu), po zapisie —
 * dalszy przepływ (`ReservationConfirmation`).
 *
 * ⚠️ `$fishery` musi być już zweryfikowane przez wołającego (bramka dostępu do łowiska) — klasa
 * pilnuje wyłącznie przynależności stanowiska i relacji do niego.
 */
final class ReservationFormSubmission
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function book(Fishery $fishery, array $data): BookingOutcome
    {
        // ⚠️ Stanowisko i relacja wędkarza przychodzą od klienta — wyłącznie z TEGO łowiska.
        $position = Position::query()->where('fishery_id', $fishery->getKey())->whereKey($data['position_id'] ?? null)->first();
        abort_if($position === null, 404);

        $angler = filled($data['fishery_angler_id'] ?? null)
            ? (self::findAngler($fishery, $data['fishery_angler_id']) ?? abort(404))
            : new AnglerDetails(
                name: (string) $data['angler_name'],
                email: $data['angler_email'] ?? null,
                phone: $data['angler_phone'] ?? null,
                notes: $data['angler_notes'] ?? null,
            );

        $outcome = (new ReservationBooking)->book(new BookingRequest(
            position: $position,
            startsOn: (string) $data['starts_on'],
            nights: (int) $data['nights'],
            angler: $angler,
            source: FisheryAccess::isAdminPanel() ? ReservationSource::Admin : ReservationSource::Operator,
            actor: auth()->user(),
            anglers: (int) ($data['anglers'] ?? 1),
            companions: (int) ($data['companions'] ?? 0),
            services: self::services($data['services'] ?? []),
            agreedTotalCents: ! empty($data['agreed']) ? ReservationActions::toCents($data['agreed_total'] ?? null) : null,
            agreedPriceReason: ! empty($data['agreed']) ? ($data['agreed_reason'] ?? null) : null,
            exceptionReason: $data['exception_reason'] ?? null,
            serviceExceptionReason: $data['service_exception_reason'] ?? null,
            internalNote: $data['internal_note'] ?? null,
            // Niepotwierdzona wygasa najpóźniej w chwili rozpoczęcia pobytu (047, E1).
            confirmBy: ReservationConfirmation::deadlineFor(
                $fishery,
                stayStartsAt: (new FishingDayCalendar($fishery))->dayStartingOn((string) $data['starts_on'])?->startsAt,
            ),
        ));

        if ($outcome->succeeded && $outcome->reservation !== null) {
            ReservationConfirmation::afterBooking($outcome->reservation);
        }

        return $outcome;
    }

    /**
     * Zmiana rezerwacji z szybkiej albo pełnej edycji (047, C1–C4).
     *
     * - Bez zmiany tego, co kształtuje cenę (`ReservationFormSchema::priceDriversChanged()`), idzie wyłącznie
     *   kontakt i notatka — warunki zostają nietknięte.
     * - Inaczej `rebook()` z powodem zmiany: kontakt w tej samej transakcji co termin; usługi z formularza
     *   (pełna edycja) albo z warunków (szybka edycja nie ma pola usług).
     *
     * ⚠️ Notatka: klucz `internal_note` obecny w danych = wartość z formularza (pusta czyści, C4); brak klucza
     * (szybka edycja) = bez zmiany.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Reservation $record, array $data): BookingOutcome
    {
        $record->loadMissing(['angler', 'terms', 'fishery']);
        $note = array_key_exists('internal_note', $data) ? $data['internal_note'] : $record->internal_note;
        $contact = new AnglerDetails(
            name: (string) ($data['angler_name'] ?? $record->angler->given_name ?? ''),
            email: $data['angler_email'] ?? null,
            phone: $data['angler_phone'] ?? null,
        );

        if (! ReservationFormSchema::priceDriversChanged($record, $data)) {
            return (new ReservationBooking)->updateContact($record, $contact, $note, auth()->user());
        }

        // ⚠️ Stanowisko przychodzi od klienta — wyłącznie z łowiska rezerwacji.
        $position = Position::query()->where('fishery_id', $record->fishery_id)->whereKey($data['position_id'] ?? $record->position_id)->first();
        abort_if($position === null || $record->angler === null, 404);

        $services = array_key_exists('services', $data)
            ? self::services((array) $data['services'])
            : collect($record->terms->services ?? [])->mapWithKeys(fn (array $line): array => [(int) $line['service_id'] => (int) $line['quantity']])->all();
        $agreed = array_key_exists('agreed', $data)
            ? (! empty($data['agreed']) ? ReservationActions::toCents($data['agreed_total'] ?? null) : null)
            : $record->terms?->agreed_total_cents;

        return (new ReservationBooking)->rebook($record, new BookingRequest(
            position: $position,
            startsOn: (string) ($data['starts_on'] ?? $record->first_night_on->toDateString()),
            nights: (int) ($data['nights'] ?? $record->nights),
            angler: $record->angler,
            source: FisheryAccess::isAdminPanel() ? ReservationSource::Admin : ReservationSource::Operator,
            actor: auth()->user(),
            anglers: (int) ($data['anglers'] ?? $record->terms->anglers ?? 1),
            companions: (int) ($data['companions'] ?? $record->terms->companions ?? 0),
            services: $services,
            agreedTotalCents: $agreed,
            agreedPriceReason: filled($data['agreed_reason'] ?? null) ? (string) $data['agreed_reason'] : null,
            exceptionReason: $data['exception_reason'] ?? null,
            serviceExceptionReason: $data['service_exception_reason'] ?? null,
            internalNote: filled($note) ? (string) $note : null,
        ), (string) ($data['change_reason'] ?? ''), $contact);
    }

    /**
     * @param  array<int|string, mixed>  $rows  wiersze repeatera `services`
     * @return array<int, int>
     */
    public static function services(array $rows): array
    {
        $services = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['service_id'] ?? null)) {
                $services[(int) $row['service_id']] = ($services[(int) $row['service_id']] ?? 0) + max(1, (int) ($row['quantity'] ?? 1));
            }
        }

        return $services;
    }

    public static function findAngler(?Fishery $fishery, mixed $id): ?FisheryAngler
    {
        if ($fishery === null || ! is_numeric($id)) {
            return null;
        }

        return FisheryAngler::query()->where('fishery_id', $fishery->getKey())->whereKey((int) $id)->first();
    }
}
