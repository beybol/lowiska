<?php

namespace App\Services;

use App\Models\AdditionalService;
use App\Models\ReservationServiceItem;

/**
 * JEDYNE miejsce liczące, ile egzemplarzy usługi z limitem zostało w ciągu dób (ADR-025 pkt 7).
 *
 * `available_count` to liczba egzemplarzy dostępnych w KAŻDEJ dobie, a egzemplarz wzięty do pobytu
 * zajmuje każdą jego dobę przy obu jednostkach rozliczenia (`dostepnosc.md` §5). Zostaje więc
 * `limit − największa suma zajętych egzemplarzy w którejkolwiek dobie zakresu`.
 *
 * ⚠️ Odczyt bez blokady jest PODPOWIEDZIĄ dla formularza („zostało N z M"). Gwarancję daje
 * `ReservationBooking`, który woła tę klasę po założeniu blokady wiersza usługi, w tej samej
 * transakcji co zapis — tabela zajętości usług nie ma ograniczenia unikalności.
 */
final class ServiceStock
{
    /**
     * Ile egzemplarzy zostało w dobach zakresu; `null` = usługa bez limitu.
     *
     * @param  array<int, FishingDay>  $nights
     * @param  int|null  $excludingReservationId  rezerwacja zmieniana — jej egzemplarze nie zajmują
     */
    public static function remaining(AdditionalService $service, array $nights, ?int $excludingReservationId = null): ?int
    {
        if ($service->available_count === null) {
            return null;
        }

        $limit = (int) $service->available_count;

        if ($nights === []) {
            return $limit;
        }

        $first = $nights[0];
        $last = $nights[count($nights) - 1];

        $items = ReservationServiceItem::query()
            ->where('additional_service_id', $service->getKey())
            ->where('starts_at', '<', $last->endsAt->utc()->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $first->startsAt->utc()->format('Y-m-d H:i:s'))
            ->when($excludingReservationId !== null, fn ($query) => $query->where('reservation_id', '!=', $excludingReservationId))
            ->get(['quantity', 'starts_at', 'ends_at']);

        $busiest = 0;

        foreach ($nights as $night) {
            $taken = 0;

            foreach ($items as $item) {
                if ($night->overlaps($item->starts_at, $item->ends_at)) {
                    $taken += $item->quantity;
                }
            }

            $busiest = max($busiest, $taken);
        }

        return max(0, $limit - $busiest);
    }
}
