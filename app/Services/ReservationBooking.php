<?php

namespace App\Services;

use App\Enums\AnglerLinkStatus;
use App\Enums\BookingProblem;
use App\Enums\ConfirmationSource;
use App\Enums\PaymentStatus;
use App\Enums\PricingFailure;
use App\Enums\ReservationStatus;
use App\Enums\SaleUnavailabilityReason;
use App\Enums\SlotType;
use App\Enums\TermsExceptionKind;
use App\Models\AdditionalService;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Models\Reservation;
use App\Models\ReservationServiceItem;
use App\Models\ReservationSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * JEDYNA ścieżka zapisu rezerwacji (ADR-025 pkt 4, G10) — zakładanie, zmiana warunków, potwierdzenie,
 * anulacja i wygaśnięcie. Wszystkie zapisy do `reservation_slots`, `reservation_service_items`
 * i `reservation_terms` idą przez tę klasę; zapis poza nią jest defektem i pilnuje tego test.
 *
 * Zapis: transakcja → blokada wiersza stanowiska (`lockForUpdate`) → NOWE pytanie do
 * `PositionAvailability` (przez warstwę oferty) → sloty, pozycje usług i warunki.
 *
 * ⚠️ **Zajętości nie omija nikt.** Kanał operatora może zapisać rezerwację wbrew regułom sprzedaży
 * i na zablokowanym stanowisku — jako wyjątek z powodem w warunkach (ADR-026 pkt 8, ADR-027 pkt 7) —
 * ale kolizja z innym slotem jest zawsze odmową `Occupied`. Dlatego zajętość sprawdza się WPROST
 * (`PositionAvailability::isOccupied()`), zanim zapyta się o ofertę: łańcuch powodów niesie tylko
 * pierwszy powód, a ten dla zablokowanego stanowiska przykryłby zajętość.
 *
 * ⚠️ Odmowa wewnątrz transakcji wycofuje wszystko (także usunięte przy zmianie terminu sloty) —
 * dlatego `refuse()` rzuca wyjątek, a wynik powstaje dopiero poza transakcją.
 */
final class ReservationBooking
{
    public function book(BookingRequest $request): BookingOutcome
    {
        return $this->attempt(fn (): Reservation => $this->write($request));
    }

    /**
     * Jawna zmiana warunków rezerwacji — terminu, stanowiska, składu, usług, ceny (ADR-026 pkt 3).
     * Zawsze z powodem i wpisem w kanale `reservations`; stare i nowe wartości zostają w dzienniku.
     */
    public function rebook(Reservation $reservation, BookingRequest $request, string $reason, ?AnglerDetails $contact = null): BookingOutcome
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A change of the reservation terms needs a reason.');
        }

        return $this->attempt(function () use ($reservation, $request, $reason, $contact): Reservation {
            // ⚠️ Kolejność blokad jak w każdej ścieżce: NAJPIERW wiersze stanowisk (rosnąco po id), potem
            // rezerwacja — ta sama co w usłudze kolizji, więc równoległe operacje się nie zakleszczają (047, F2).
            $current = Reservation::query()->whereKey($reservation->getKey())->firstOrFail();
            $this->lockPositions([(int) $request->position->getKey(), (int) $current->position_id]);
            $locked = Reservation::query()->with(['terms', 'angler'])->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive() || (int) $locked->position_id !== (int) $current->position_id) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::NotActive));
            }

            if ((int) $request->position->fishery_id !== (int) $locked->fishery_id) {
                throw new InvalidArgumentException('A reservation can not move to a position of another fishery.');
            }

            if ($contact !== null && $locked->angler !== null) {
                $this->applyContact($locked->angler, $contact);
            }

            return $this->write($request, $locked, $reason);
        });
    }

    /**
     * Zmiana samych danych kontaktowych relacji i notatki — BEZ przeliczania warunków (047, C2).
     *
     * Dane operatora poprawiają wyłącznie relację niepowiązaną (ADR-024 — do `users` operator nie pisze).
     * ⚠️ Zmiana adresu unieważnia wysłaną prośbę o powiązanie (047, D2) — inaczej stary link z maila
     * wysłanego na pomylony adres dowiązałby rezerwację do konta właściciela poprawionego adresu.
     */
    public function updateContact(Reservation $reservation, AnglerDetails $contact, ?string $internalNote, ?User $actor = null): BookingOutcome
    {
        return $this->attempt(function () use ($reservation, $contact, $internalNote): Reservation {
            $locked = Reservation::query()->with('angler')->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::NotActive));
            }

            if ($locked->angler !== null) {
                $this->applyContact($locked->angler, $contact);
            }

            $note = $internalNote !== null && trim($internalNote) !== '' ? $internalNote : null;

            if ($locked->internal_note !== $note) {
                $locked->update(['internal_note' => $note]);
            }

            return $locked->refresh();
        });
    }

    public function confirm(Reservation $reservation, ConfirmationSource $source, ?User $actor = null): BookingOutcome
    {
        return $this->attempt(function () use ($reservation, $source): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::NotActive));
            }

            if ($locked->status === ReservationStatus::Pending) {
                $locked->update([
                    'status' => ReservationStatus::Confirmed,
                    'confirmed_at' => CarbonImmutable::now(),
                    'confirmation_source' => $source,
                    'confirm_by' => null,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Anulacja: zwalnia sloty i egzemplarze usług, rezerwacja zostaje w historii (ADR-025 pkt 6).
     *
     * Zwrot domyślnie wynika z warunków umowy — przy anulacji przez łowisko zawsze pełny (G8),
     * przy rezygnacji wędkarza z polityki zwrotu zamrożonej w warunkach. Inny zwrot to jawny wyjątek
     * z powodem (ADR-026, aktualizacja 10.10). W etapie 3 zwrot jest STATUSEM płatności (ADR-027 pkt 5).
     */
    public function cancel(Reservation $reservation, CancellationRequest $request): BookingOutcome
    {
        if ($request->refundPercent !== null && trim((string) $request->refundReason) === '') {
            throw new InvalidArgumentException('A refund other than the policy needs a reason.');
        }

        return $this->attempt(function () use ($reservation, $request): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::NotActive));
            }

            $now = CarbonImmutable::now();
            $percent = $request->refundPercent ?? $this->refundPercentFromTerms($locked, $request, $now);

            if ($request->refundPercent !== null) {
                // Wyjątek od zwrotu ma powód i autora — bez warunków nie ma go gdzie zapisać, więc nie
                // stosujemy procentu po cichu (047, C6). Warunki powstają z każdą rezerwacją.
                $terms = $locked->terms ?? throw new LogicException("Reservation {$locked->getKey()} has no terms to record the refund exception.");
                $terms->update(['exceptions' => array_merge($terms->exceptions ?? [], [
                    ReservationTermsSnapshot::exception(
                        TermsExceptionKind::Refund,
                        (string) $request->refundReason,
                        $request->actor,
                        ['percent' => $request->refundPercent, 'policy_percent' => $this->refundPercentFromTerms($locked, $request, $now)],
                    ),
                ])]);
            }

            $locked->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by' => $request->actor?->getKey(),
                'cancel_reason' => $request->reason,
                'cancel_message' => filled($request->message) ? $request->message : null,
                'confirm_by' => null,
                'payment_status' => $locked->payment_status === PaymentStatus::Paid && $percent > 0
                    ? PaymentStatus::Refund
                    : $locked->payment_status,
                'needs_contact_at' => $request->needsContact ? $now : $locked->needs_contact_at,
            ]);

            $this->release($locked);

            return $locked;
        });
    }

    /**
     * Jawny wyjątek dopisany do warunków istniejącej rezerwacji — np. decyzja „zostaw" przy blokadzie
     * albo wycofaniu stanowiska (ADR-027 pkt 3, 7). Zawsze z powodem, autorem i chwilą.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordException(Reservation $reservation, TermsExceptionKind $kind, string $reason, ?User $actor = null, array $details = []): void
    {
        DB::transaction(function () use ($reservation, $kind, $reason, $actor, $details): void {
            $terms = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail()->terms;

            if ($terms === null) {
                return;
            }

            $terms->update(['exceptions' => array_merge($terms->exceptions ?? [], [
                ReservationTermsSnapshot::exception($kind, $reason, $actor, $details),
            ])]);
        });
    }

    /**
     * Status płatności (etap 3: płatność na łowisku). Zwrot ustawia wyłącznie anulacja.
     *
     * ⚠️ **Opłacona rezerwacja jest zawsze potwierdzona** — oznaczenie płatności potwierdza oczekującą
     * rezerwację (źródło „płatność") i zdejmuje termin wygaśnięcia. Ta sama reguła obowiązuje płatność
     * wędkarza online: ma przejść tędy, a nie obok.
     */
    public function setPaymentStatus(Reservation $reservation, PaymentStatus $status): BookingOutcome
    {
        return $this->attempt(function () use ($reservation, $status): Reservation {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive() || $status === PaymentStatus::Refund) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::NotActive));
            }

            $attributes = ['payment_status' => $status];

            if ($status === PaymentStatus::Paid && $locked->status === ReservationStatus::Pending) {
                $attributes += [
                    'status' => ReservationStatus::Confirmed,
                    'confirmed_at' => CarbonImmutable::now(),
                    'confirmation_source' => ConfirmationSource::Payment,
                    'confirm_by' => null,
                ];
            }

            $locked->update($attributes);

            return $locked;
        });
    }

    /** Wędkarza trzeba powiadomić inną drogą — brak adresu albo błąd wysyłki (ADR-027 pkt 4). */
    public function markNeedsContact(Reservation $reservation): void
    {
        $reservation->update(['needs_contact_at' => CarbonImmutable::now(), 'contacted_at' => null]);
    }

    /** „Oznacz jako załatwione" na liście „Do kontaktu". */
    public function markContacted(Reservation $reservation): void
    {
        $reservation->update(['contacted_at' => CarbonImmutable::now()]);
    }

    /**
     * Wygaszenie niepotwierdzonej rezerwacji po `confirm_by` (ADR-024 pkt 7). Idempotentne: rezerwacja
     * już potwierdzona, anulowana albo wygaszona — albo przed terminem — zwraca `false` i niczego nie zmienia.
     */
    public function expire(Reservation $reservation, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        return DB::transaction(function () use ($reservation, $now): bool {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== ReservationStatus::Pending
                || $locked->confirm_by === null
                || $locked->confirm_by > $now) {
                return false;
            }

            // Pobyt w toku nie wygasa (047, E1). Termin potwierdzenia nie przekracza początku pobytu, więc
            // rezerwacja wygasa najpóźniej w chwili rozpoczęcia — harmonogram co 5 minut może to zrobić chwilę
            // po starcie. Pomijamy wyłącznie rezerwacje z terminem PO początku pobytu (sprzed tej reguły).
            if ($locked->starts_at <= $now && $locked->confirm_by > $locked->starts_at) {
                return false;
            }

            $locked->update([
                'status' => ReservationStatus::Expired,
                'expired_at' => $now,
            ]);

            $this->release($locked);

            return true;
        });
    }

    /**
     * @param  callable(): Reservation  $operation
     */
    private function attempt(callable $operation): BookingOutcome
    {
        try {
            return BookingOutcome::succeeded(DB::transaction($operation));
        } catch (BookingRefused $refusal) {
            return $refusal->outcome;
        }
    }

    private function write(BookingRequest $request, ?Reservation $existing = null, ?string $changeReason = null): Reservation
    {
        if ($request->nights < 1 || $request->anglers < 1 || $request->companions < 0) {
            throw new InvalidArgumentException('A stay needs at least one night and one angler.');
        }

        // 1. Blokada wiersza stanowiska — przy zmianie stanowiska obu, rosnąco po id (bez zakleszczeń).
        $positionIds = array_values(array_unique(array_filter([
            (int) $request->position->getKey(),
            $existing !== null ? (int) $existing->position_id : null,
        ])));
        sort($positionIds);

        $locked = Position::query()->whereKey($positionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $position = $locked->get((int) $request->position->getKey())
            ?? throw new InvalidArgumentException('The position does not exist.');
        $position->load('fishery');

        if ($position->fishery === null) {
            throw new InvalidArgumentException("Position {$position->id} has no fishery.");
        }

        $fishery = $position->fishery;

        // Zmiana warunków: własne sloty i egzemplarze nie kolidują z nowym terminem. Odmowa niżej
        // wycofa ich usunięcie razem z całą transakcją.
        if ($existing !== null) {
            $this->release($existing);
        }

        // 2. Doby pobytu — liczy je wyłącznie `FishingDayCalendar` (`dostepnosc.md` §1).
        $calendar = new FishingDayCalendar($fishery);
        $first = CarbonImmutable::parse($request->startsOn, $fishery->timezoneName())->startOfDay();
        $nights = [];

        for ($offset = 0; $offset < $request->nights; $offset++) {
            $night = $calendar->dayStartingOn($first->addDays($offset));

            if (! $night instanceof FishingDay) {
                $this->refuseWith(BookingOutcome::refused(SaleUnavailabilityReason::FishingDayNotConfigured));
            }

            $nights[] = $night;
        }

        // 3. Zajętość — nowa instancja, czyta stan PO założeniu blokady. Nieomijalna.
        $availability = new PositionAvailability($position);

        foreach ($nights as $night) {
            if ($availability->isOccupied($night)) {
                $this->refuseWith(BookingOutcome::refused(SaleUnavailabilityReason::Occupied, $night->startsOn));
            }
        }

        // 4. Pojemność — walidacja składu, nie zajętość (ADR-025 pkt 8).
        if (($position->max_anglers !== null && $request->anglers > (int) $position->max_anglers)
            || ($position->max_people !== null && $request->anglers + $request->companions > (int) $position->max_people)) {
            $this->refuseWith(BookingOutcome::problem(BookingProblem::CapacityExceeded));
        }

        // 5. Oferta: sprzedawalność, potem cena — z wyjątkami operatora.
        //
        // ⚠️ Zmiana warunków (047, C2) NIE zaczyna od zera: istniejące wyjątki są punktem wyjścia. Wyjątek
        // od tej samej reguły nie wymaga ponownego powodu; „zostaw" z kolizji (`block_id`) i zwroty zostają
        // zawsze; wyjątki od reguł, których nowy termin już nie narusza, odpadają (historia w dzienniku).
        $previous = $existing?->terms->exceptions ?? [];
        $offer = new StayOffer($position);
        $verdict = $offer->offer($first, $request->nights, $request->anglers, $request->companions);
        $exceptions = array_values(array_filter($previous, fn (array $exception): bool => ! in_array($exception['kind'] ?? null, [
            TermsExceptionKind::SaleRule->value, TermsExceptionKind::SaleBlocked->value, TermsExceptionKind::AgreedPrice->value, TermsExceptionKind::Service->value,
        ], true) || isset($exception['block_id'])));
        $breakdown = $verdict->breakdown;
        $unpriced = null;

        if (! $verdict->available) {
            $reason = $verdict->reason ?? SaleUnavailabilityReason::NoPriceDefined;

            if ($verdict->sellability !== null) {
                $night = $verdict->sellability->day?->startsOn;

                if (! $request->source->allowsExceptions()) {
                    $this->refuseWith(BookingOutcome::refused($reason, $night));
                }

                $kind = $reason === SaleUnavailabilityReason::SaleBlocked ? TermsExceptionKind::SaleBlocked : TermsExceptionKind::SaleRule;
                $carried = array_values(array_filter($previous, fn (array $exception): bool => ($exception['kind'] ?? null) === $kind->value
                    && ($exception['rule'] ?? null) === $reason->value
                    && ! isset($exception['block_id'])));
                $given = trim((string) $request->exceptionReason);

                // Blokada zostawiona decyzją przy kolizji (wyjątek z `block_id`) już uzasadnia tę dobę.
                $coveredByCollision = $kind === TermsExceptionKind::SaleBlocked && collect($previous)
                    ->contains(fn (array $exception): bool => ($exception['kind'] ?? null) === TermsExceptionKind::SaleBlocked->value && isset($exception['block_id']));

                if ($given === '' && $carried === [] && ! $coveredByCollision) {
                    $this->refuseWith(BookingOutcome::problem(BookingProblem::ExceptionReasonRequired, $reason, $night));
                }

                array_push($exceptions, ...$carried);

                if ($given !== '' && ! collect($carried)->contains(fn (array $exception): bool => ($exception['reason'] ?? null) === $given)) {
                    $exceptions[] = ReservationTermsSnapshot::exception(
                        $kind,
                        $given,
                        $request->actor,
                        ['rule' => $reason->value, 'night' => $night?->toDateString()],
                    );
                }

                // Pobyt niesprzedawalny warstwa oferty pomija przy wycenie — wyjątek operatora
                // potrzebuje ceny wyliczonej, więc pyta cennik wprost.
                $breakdown = (new StayPricing($position))->breakdown($first, $request->nights, $request->anglers, $request->companions);

                if (! $breakdown->isPriced()) {
                    $unpriced = [$breakdown->failure === PricingFailure::NoCompanionPrice
                        ? SaleUnavailabilityReason::NoCompanionPrice
                        : SaleUnavailabilityReason::NoPriceDefined, $breakdown->failedNight];
                    $breakdown = null;
                }
            } else {
                $unpriced = [$reason, $verdict->unpricedNight];
                $breakdown = null;
            }
        }

        // 6. Cena uzgodniona — tylko kanał operatora, zawsze z powodem; cena wyliczona zostaje obok.
        // Ta sama kwota co dotąd przy zmianie warunków nie wymaga ponownego powodu (047, C2).
        $previousAgreed = $existing?->terms?->agreed_total_cents;
        $agreedUnchanged = $request->agreedTotalCents !== null && $request->agreedTotalCents === $previousAgreed
            && trim((string) $request->agreedPriceReason) === '';

        if ($request->agreedTotalCents !== null) {
            if (! $request->source->allowsExceptions() || (trim((string) $request->agreedPriceReason) === '' && ! $agreedUnchanged)) {
                $this->refuseWith(BookingOutcome::problem(BookingProblem::AgreedPriceReasonRequired));
            }
        } elseif ($unpriced !== null) {
            $this->refuseWith(BookingOutcome::refused($unpriced[0], $unpriced[1]));
        }

        // 7. Usługi — pod blokadą wiersza każdej usługi z limitem, ponowne liczenie po blokadzie.
        $quote = $offer->services($first, $request->nights, $request->services, $existing?->getKey());

        if ($quote->unknownServiceIds !== []) {
            $this->refuseWith(BookingOutcome::problem(BookingProblem::ServiceUnavailable, serviceId: $quote->unknownServiceIds[0]));
        }

        $limited = [];

        foreach ($quote->chosen() as $line) {
            $serviceId = (int) $line->service->getKey();

            // Usługa nieoferowana na stanowisku albo bez wymaganej cechy: wyjątek operatora z powodem (047, B6).
            // ⚠️ Wyczerpany limit egzemplarzy wyjątku NIE ma — sprawdza go blok niżej, dla każdego kanału.
            if (! $line->isAvailable()) {
                if (! $request->source->allowsExceptions()) {
                    $this->refuseWith(BookingOutcome::problem(BookingProblem::ServiceUnavailable, serviceId: $serviceId));
                }

                $carried = array_values(array_filter($previous, fn (array $exception): bool => ($exception['kind'] ?? null) === TermsExceptionKind::Service->value
                    && (int) ($exception['service_id'] ?? 0) === $serviceId));
                $given = trim((string) $request->serviceExceptionReason);

                if ($given === '' && $carried === []) {
                    $this->refuseWith(BookingOutcome::problem(BookingProblem::ServiceExceptionReasonRequired, serviceId: $serviceId));
                }

                array_push($exceptions, ...$carried);

                if ($given !== '' && ! collect($carried)->contains(fn (array $exception): bool => ($exception['reason'] ?? null) === $given)) {
                    $exceptions[] = ReservationTermsSnapshot::exception(TermsExceptionKind::Service, $given, $request->actor, [
                        'service_id' => $serviceId,
                        'service' => $line->service->name,
                        'causes' => $line->warnings(),
                    ]);
                }
            }

            if ($line->availableCount !== null) {
                $limited[$serviceId] = $line;
            }
        }

        if ($limited !== []) {
            ksort($limited);
            $services = AdditionalService::query()->whereKey(array_keys($limited))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($limited as $id => $line) {
                $service = $services->get($id) ?? $line->service;
                $remaining = ServiceStock::remaining($service, $nights, $existing?->getKey());

                if ($remaining !== null && $line->quantity > $remaining) {
                    $this->refuseWith(BookingOutcome::problem(BookingProblem::ServiceLimitExceeded, serviceId: $id));
                }
            }
        }

        if ($agreedUnchanged) {
            array_push($exceptions, ...array_values(array_filter($previous, fn (array $exception): bool => ($exception['kind'] ?? null) === TermsExceptionKind::AgreedPrice->value)));
        } elseif ($request->agreedTotalCents !== null) {
            $computed = $breakdown === null ? null : $breakdown->totalInCents() + $quote->totalInCents();
            $exceptions[] = ReservationTermsSnapshot::exception(
                TermsExceptionKind::AgreedPrice,
                (string) $request->agreedPriceReason,
                $request->actor,
                ['computed_total_cents' => $computed, 'agreed_total_cents' => $request->agreedTotalCents],
            );
        }

        // 8. Zapis.
        $before = [];
        $angler = $this->angler($request, $fishery->getKey());
        $stayStart = $nights[0]->startsAt;
        $stayEnd = $nights[count($nights) - 1]->endsAt;

        $attributes = [
            'fishery_id' => $fishery->getKey(),
            'position_id' => $position->getKey(),
            'fishery_angler_id' => $angler->getKey(),
            'starts_at' => $stayStart,
            'ends_at' => $stayEnd,
            'first_night_on' => $first->toDateString(),
            'nights' => $request->nights,
            'internal_note' => $request->internalNote,
        ];

        if ($existing === null) {
            $confirmed = $request->confirmBy === null;
            $reservation = Reservation::query()->create(array_merge($attributes, [
                'status' => $confirmed ? ReservationStatus::Confirmed : ReservationStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'confirm_by' => $request->confirmBy,
                'confirmed_at' => $confirmed ? CarbonImmutable::now() : null,
                'confirmation_source' => $confirmed ? ConfirmationSource::Operator : null,
                'source' => $request->source,
                'created_by' => $request->actor?->getKey(),
            ]));
        } else {
            $reservation = $existing;
            $before = $reservation->only(['position_id', 'first_night_on', 'nights', 'fishery_angler_id']);
            $reservation->update($attributes);
        }

        foreach ($nights as $night) {
            ReservationSlot::query()->create([
                'reservation_id' => $reservation->getKey(),
                'position_id' => $position->getKey(),
                'slot_type' => SlotType::FishingDay,
                'starts_at' => $night->startsAt,
                'ends_at' => $night->endsAt,
            ]);
        }

        foreach ($quote->chosen() as $line) {
            ReservationServiceItem::query()->create([
                'reservation_id' => $reservation->getKey(),
                'additional_service_id' => $line->service->getKey(),
                'quantity' => $line->quantity,
                'starts_at' => $stayStart,
                'ends_at' => $stayEnd,
            ]);
        }

        $terms = ReservationTermsSnapshot::build(
            $position,
            $nights,
            $request->anglers,
            $request->companions,
            $breakdown,
            $quote,
            $request->agreedTotalCents,
            $exceptions,
            $angler->effectiveContact(),
        );

        if ($existing === null) {
            $reservation->terms()->create($terms);
        } else {
            $terms = $this->keepFrozen($existing, $before, $terms, $request, $first);
            $reservation->terms()->updateOrCreate([], $terms);

            activity(Reservation::LOG_NAME)
                ->performedOn($reservation)
                ->causedBy($request->actor)
                ->event('terms_changed')
                ->withChanges([
                    'old' => $before,
                    'attributes' => $reservation->only(['position_id', 'first_night_on', 'nights', 'fishery_angler_id']),
                ])
                ->withProperties(['reason' => $changeReason])
                ->log('terms_changed');
        }

        return $reservation->refresh();
    }

    /**
     * Relacja łowiska z wędkarzem: wskazana wprost albo znaleziona po adresie e-mail w TYM łowisku
     * (ADR-024 pkt 2 — bez globalnej wyszukiwarki). Telefon nie łączy relacji automatycznie (pkt 4).
     */
    private function angler(BookingRequest $request, int $fisheryId): FisheryAngler
    {
        $angler = $request->angler;

        if ($angler instanceof FisheryAngler) {
            if ((int) $angler->fishery_id !== $fisheryId) {
                throw new InvalidArgumentException('The angler relation belongs to another fishery.');
            }

            return $angler;
        }

        $email = FisheryAngler::normalizeEmail($angler->email);
        $existing = $email === null ? null : FisheryAngler::query()
            ->where('fishery_id', $fisheryId)
            ->where('given_email_normalized', $email)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return FisheryAngler::query()->create([
                'fishery_id' => $fisheryId,
                'status' => AnglerLinkStatus::Unlinked,
                'given_name' => $angler->name,
                'given_email' => $angler->email,
                'given_phone' => $angler->phone,
                'notes' => $angler->notes,
            ]);
        }

        // Dane operatora poprawiają WYŁĄCZNIE relację niepowiązaną — konta wędkarza operator nie pisze.
        if (in_array($existing->status, [AnglerLinkStatus::Unlinked, AnglerLinkStatus::Pending], true)) {
            $existing->fill(array_filter([
                'given_name' => $angler->name,
                'given_phone' => $angler->phone,
            ], fn ($value): bool => filled($value)));

            if ($existing->isDirty()) {
                $existing->save();
            }
        }

        return $existing;
    }

    /**
     * Przy zmianie warunków zostaje to, co zamrożone (ADR-026 pkt 3 i 5; 047, C2):
     * - polityka zwrotu, wersje dokumentów i kontakt z chwili rezerwacji — nigdy z bieżącej konfiguracji;
     * - cena z rozbiciem i usługi — dopóki nie zmieniło się nic, co je kształtuje (stanowisko, termin,
     *   skład, usługi). Zmiana samej notatki czy telefonu nie przelicza ceny po dzisiejszym cenniku.
     *
     * @param  array<string, mixed>  $before  stanowisko, pierwsza doba i liczba dób SPRZED zmiany
     * @param  array<string, mixed>  $terms  świeżo złożone warunki
     * @return array<string, mixed>
     */
    private function keepFrozen(Reservation $existing, array $before, array $terms, BookingRequest $request, CarbonImmutable $first): array
    {
        $old = $existing->terms;

        if ($old === null) {
            return $terms;
        }

        $terms['refund_policy'] = $old->refund_policy;
        $terms['documents'] = $old->documents;
        $terms['contact_snapshot'] = $old->contact_snapshot;

        $oldServices = collect($old->services ?? [])->mapWithKeys(fn (array $line): array => [(int) $line['service_id'] => (int) $line['quantity']])->sortKeys()->all();
        $newServices = collect($terms['services'])->mapWithKeys(fn (array $line): array => [(int) $line['service_id'] => (int) $line['quantity']])->sortKeys()->all();

        $beforeFirst = $before['first_night_on'] ?? null;

        $priceDriversUnchanged = (int) ($before['position_id'] ?? 0) === (int) $request->position->getKey()
            && $beforeFirst !== null
            && CarbonImmutable::parse($beforeFirst instanceof \DateTimeInterface ? $beforeFirst->format('Y-m-d') : (string) $beforeFirst)->toDateString() === $first->toDateString()
            && (int) ($before['nights'] ?? 0) === $request->nights
            && $old->anglers === $request->anglers
            && $old->companions === $request->companions
            && $oldServices === $newServices;

        if ($priceDriversUnchanged) {
            foreach (['price_breakdown', 'stay_total_cents', 'services', 'services_total_cents', 'computed_total_cents'] as $field) {
                $terms[$field] = $old->{$field};
            }

            $terms['total_cents'] = $terms['agreed_total_cents'] ?? (int) $old->computed_total_cents;
        }

        return $terms;
    }

    /**
     * Dane kontaktowe relacji od operatora (ADR-024) — wyłącznie relacja niepowiązana.
     */
    private function applyContact(FisheryAngler $angler, AnglerDetails $contact): void
    {
        if (! in_array($angler->status, [AnglerLinkStatus::Unlinked, AnglerLinkStatus::Pending], true)) {
            return;
        }

        $email = FisheryAngler::normalizeEmail($contact->email);

        if ($email !== $angler->given_email_normalized && $email !== null && FisheryAngler::query()
            ->where('fishery_id', $angler->fishery_id)
            ->where('given_email_normalized', $email)
            ->whereKeyNot($angler->getKey())
            ->exists()) {
            $this->refuseWith(BookingOutcome::problem(BookingProblem::EmailTaken));
        }

        $emailChanged = $email !== $angler->given_email_normalized;

        $angler->fill([
            'given_name' => filled($contact->name) ? $contact->name : $angler->given_name,
            'given_phone' => filled($contact->phone) ? $contact->phone : null,
            'given_email' => $email === null ? null : trim((string) $contact->email),
        ]);

        // ⚠️ Nowy adres = stara prośba o powiązanie przestaje działać (047, D2).
        if ($emailChanged) {
            $angler->forceFill([
                'link_token_hash' => null,
                'link_expires_at' => null,
                'link_email' => null,
                'status' => AnglerLinkStatus::Unlinked,
            ]);
        }

        if ($angler->isDirty()) {
            $angler->save();
        }
    }

    /**
     * @param  array<int, int>  $positionIds
     */
    private function lockPositions(array $positionIds): void
    {
        $ids = array_values(array_unique(array_filter($positionIds)));
        sort($ids);

        Position::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Zwolnienie zasobu: sloty i egzemplarze usług znikają, rezerwacja i warunki zostają (ADR-025 pkt 6).
     */
    private function release(Reservation $reservation): void
    {
        ReservationSlot::query()->where('reservation_id', $reservation->getKey())->delete();
        ReservationServiceItem::query()->where('reservation_id', $reservation->getKey())->delete();
    }

    /**
     * Procent zwrotu z warunków umowy: anulacja przez łowisko — 100% (G8); rezygnacja wędkarza —
     * polityka zamrożona w warunkach; pobyt rozpoczęty albo polityka nieustawiona — 0%.
     */
    private function refundPercentFromTerms(Reservation $reservation, CancellationRequest $request, CarbonImmutable $now): int
    {
        if ($request->reason->isByFishery()) {
            return 100;
        }

        $decision = RefundPolicy::frozen($reservation->fishery, $reservation->terms?->refund_policy)
            ->forCancellation($reservation->first_night_on, $now);

        return $decision->percent ?? 0;
    }

    private function refuseWith(BookingOutcome $outcome): never
    {
        throw new BookingRefused($outcome);
    }
}
