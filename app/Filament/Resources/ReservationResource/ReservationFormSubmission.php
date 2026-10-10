<?php

namespace App\Filament\Resources\ReservationResource;

use App\Enums\ReservationSource;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Services\AnglerDetails;
use App\Services\BookingOutcome;
use App\Services\BookingRequest;
use App\Services\FisheryAccess;
use App\Services\ReservationBooking;
use App\Services\ReservationConfirmation;

/**
 * JEDNO przejście z danych formularza rezerwacji do `ReservationBooking` — dla pełnej strony C
 * (`CreateReservation`) i krótkiego formularza w kalendarzu (zadanie 042). Dwa formularze, jedna
 * interpretacja pól: stanowisko i relacja wędkarza tylko z TEGO łowiska, kanał operatora/admina,
 * termin potwierdzenia z ustawień łowiska, po zapisie — dalszy przepływ (`ReservationConfirmation`).
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
            internalNote: $data['internal_note'] ?? null,
            confirmBy: ReservationConfirmation::deadlineFor($fishery),
        ));

        if ($outcome->succeeded && $outcome->reservation !== null) {
            ReservationConfirmation::afterBooking($outcome->reservation);
        }

        return $outcome;
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

    public static function findAngler(Fishery $fishery, mixed $id): ?FisheryAngler
    {
        if (! is_numeric($id)) {
            return null;
        }

        return FisheryAngler::query()->where('fishery_id', $fishery->getKey())->whereKey((int) $id)->first();
    }
}
