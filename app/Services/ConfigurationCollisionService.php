<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\CollisionDecision;
use App\Enums\ConfigurationChangeKind;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * JEDNA usługa kolizji zmian konfiguracji z rezerwacjami (ADR-027, zadanie 044, zasada G8).
 *
 * Woła ją wyłącznie kod zapisu zmiany (strony zapisu blokady i stanowiska, ekran decyzji), nigdy
 * definicja zasobu Filamenta. Dla opisu zmiany zwraca dotknięte rezerwacje, a `apply()` zapisuje zmianę
 * i wszystkie decyzje w JEDNEJ transakcji; maile o anulacji idą po zatwierdzeniu.
 *
 * ⚠️ **Dotknięta rezerwacja = aktywna, jeszcze nierozpoczęta, nakładająca się na zmianę** (ADR-027 pkt 2).
 * Pobytów w toku nie dotykamy w ogóle — nie trafiają na listę i żadna zmiana konfiguracji ich nie zmienia.
 * Rezerwacja już zostawiona przy TEJ blokadzie (wyjątek z `block_id`) nie wraca na listę przy jej edycji.
 *
 * ⚠️ **Maile bez limitu, wysyłane po kolei** (rozstrzygnięcie Q6 autora, 10.10.2026) — świadome ryzyko
 * długiego żądania przy dużej liczbie rezerwacji. Zmiana i anulacje są już zapisane, zanim pierwszy mail
 * wyjdzie; błąd pojedynczej wysyłki nie przerywa pętli, a rezerwacja trafia do „Do kontaktu".
 */
final class ConfigurationCollisionService
{
    public function __construct(
        private readonly ReservationBooking $booking = new ReservationBooking,
        private readonly ReservationMailer $mailer = new ReservationMailer,
    ) {}

    /**
     * @return Collection<int, Reservation>
     */
    public function affected(ConfigurationChange $change, ?CarbonImmutable $now = null): Collection
    {
        if ($change->positionIds === []) {
            return new Collection;
        }

        $fishery = Fishery::query()->findOrFail($change->fisheryId);
        $now = CarbonImmutable::instance($now ?? now());

        $query = Reservation::query()
            ->where('fishery_id', $fishery->getKey())
            ->whereIn('position_id', $change->positionIds)
            ->active()
            // Pobyty w toku i minione — poza listą (ADR-027 pkt 2).
            ->where('starts_at', '>', $now->utc()->format('Y-m-d H:i:s'));

        if ($change->kind !== ConfigurationChangeKind::PositionWithdrawal && $change->startsOn !== null) {
            // Okno blokady: doby od „od" do „do", z przełomem dnia o godzinie doby (`dostepnosc.md` §3),
            // semantyka nakładania — rezerwacja kończąca się w chwili startu blokady z nią nie koliduje.
            [$windowStart, $windowEnd] = (new FishingDayCalendar($fishery))->nightsWindow($change->startsOn, $change->endsOn);
            $query->where('ends_at', '>', $windowStart->utc()->format('Y-m-d H:i:s'));

            if ($windowEnd !== null) {
                $query->where('starts_at', '<', $windowEnd->utc()->format('Y-m-d H:i:s'));
            }
        }

        $blockId = $change->payload['block_id'] ?? null;

        return $query->with(['angler.user', 'terms', 'position', 'fishery'])
            ->orderBy('starts_at')
            ->get()
            ->reject(fn (Reservation $reservation): bool => $blockId !== null && collect($reservation->terms->exceptions ?? [])
                ->contains(fn (array $exception): bool => ($exception['block_id'] ?? null) === (int) $blockId))
            ->values();
    }

    /**
     * Zapis zmiany i decyzji w jednej transakcji, potem maile o anulacji.
     *
     * @param  array<int|string, string>  $decisions  id rezerwacji → `cancel` · `keep`
     * @param  array<int|string, string>  $messages  id rezerwacji → komunikat indywidualny (nadpisuje wspólny)
     * @param  callable(): ?int  $persist  zapis samej zmiany (blokada, wycofanie); zwraca id zapisanej blokady,
     *                                     żeby wyjątek „zostaw" wskazywał blokadę także przy nowej
     */
    public function apply(
        ConfigurationChange $change,
        array $decisions,
        callable $persist,
        ?User $actor = null,
        ?string $message = null,
        array $messages = [],
        string $reason = '',
    ): CollisionResult {
        $cancelled = [];
        $kept = [];

        DB::transaction(function () use ($change, $decisions, $persist, $actor, $message, $messages, $reason, &$cancelled, &$kept): void {
            // Ta sama blokada wierszy stanowisk co w ścieżce zapisu rezerwacji — nowa rezerwacja nie
            // wejdzie między wyliczenie listy a zapis zmiany. Kolejność jak w `ReservationBooking`:
            // stanowiska rosnąco po id, potem wiersze rezerwacji (w `cancel()`) — bez zakleszczeń (047, F2).
            Position::query()->whereKey($change->positionIds)->orderBy('id')->lockForUpdate()->get();

            $affected = $this->affected($change);

            foreach ($affected as $reservation) {
                if (! isset($decisions[$reservation->getKey()])) {
                    throw new StaleCollisionDecision;
                }
            }

            $savedBlockId = $persist();

            foreach ($affected as $reservation) {
                $decision = CollisionDecision::tryFrom((string) $decisions[$reservation->getKey()]);

                if ($decision === null || ! in_array($decision, $change->kind->decisions(), true)) {
                    throw new InvalidArgumentException("Decision for reservation {$reservation->getKey()} is not allowed.");
                }

                if ($decision === CollisionDecision::Cancel) {
                    $text = trim((string) ($messages[$reservation->getKey()] ?? '')) !== ''
                        ? (string) $messages[$reservation->getKey()]
                        : $message;

                    $outcome = $this->booking->cancel($reservation, new CancellationRequest(
                        reason: CancellationReason::Collision,
                        actor: $actor,
                        message: filled($text) ? $text : null,
                    ));

                    // Anulowana w międzyczasie inną drogą: nie liczy się i nie dostaje drugiego maila (047, F2).
                    if ($outcome->succeeded) {
                        $cancelled[] = $reservation;
                    }

                    continue;
                }

                $this->booking->recordException(
                    $reservation,
                    $change->kind->keepException(),
                    trim($change->kind->label().($reason !== '' ? ': '.$reason : '')),
                    $actor,
                    array_filter(['block_id' => isset($change->payload['block_id']) ? (int) $change->payload['block_id'] : (is_int($savedBlockId) ? $savedBlockId : null)]),
                );
                $kept[] = $reservation;
            }
        });

        $mailed = 0;

        foreach ($cancelled as $reservation) {
            if ($this->mailer->cancellation($reservation->fresh() ?? $reservation)) {
                $mailed++;
            }
        }

        return new CollisionResult(count($cancelled), count($kept), $mailed, count($cancelled) - $mailed);
    }
}
