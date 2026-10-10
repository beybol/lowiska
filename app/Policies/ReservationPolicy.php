<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Rezerwacja należy do łowiska, więc widoczność zawęża się po właścicielu łowiska — wzorzec
 * identyczny jak w `AvailabilityBlockPolicy` i `PositionPolicy` (zadanie 041).
 *
 * ⚠️ Modele zależne (`FisheryAngler`, `ReservationSlot`, `ReservationTerms`,
 * `ReservationServiceItem`) polityk NIE dostają — nie mają zasobu Filamenta, więc `shield:generate`
 * nie utworzyłby dla nich uprawnień (`docs/conventions/autoryzacja.md` §5). Autoryzują się przez
 * rezerwację albo łowisko.
 *
 * ⚠️ Rezerwacji się nie usuwa — anulacja zostawia historię (ADR-025 pkt 6), więc `delete` i pokrewne
 * zwracają uprawnienie Shielda, którego właściciel nie ma.
 */
class ReservationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:reservation') || $user->hasRole('owner');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->can('view:reservation') || $this->ownsFishery($user, $reservation);
    }

    public function create(User $user): bool
    {
        return $user->can('create:reservation') || $user->hasRole('owner');
    }

    /** Zmiana warunków, potwierdzenie, status płatności, anulacja i „do kontaktu". */
    public function update(User $user, Reservation $reservation): bool
    {
        return $user->can('update:reservation') || $this->ownsFishery($user, $reservation);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->can('delete:reservation');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:reservation');
    }

    public function forceDelete(User $user, Reservation $reservation): bool
    {
        return $user->can('force_delete:reservation');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:reservation');
    }

    public function restore(User $user, Reservation $reservation): bool
    {
        return $user->can('restore:reservation');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:reservation');
    }

    public function replicate(User $user, Reservation $reservation): bool
    {
        return $user->can('replicate:reservation');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder:reservation');
    }

    private function ownsFishery(User $user, Reservation $reservation): bool
    {
        return $user->hasRole('owner') && $reservation->fishery?->user_id === $user->id;
    }
}
