<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PositionStatus;
use App\Enums\ReservationStatus;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Model siatki warstwy „Rezerwacje" kalendarza (zadanie 042, zasada G4) — widok niczego nie liczy.
 *
 * ⚠️ **Zajętość i blokady pochodzą z `PositionAvailability`** (`isOccupied()`, `isBlocked()`) — tego
 * samego źródła, które sprawdza ścieżka zapisu. Siatka nie ma własnej reguły nakładania. Paski
 * rezerwacji rysuje się z przedziału pobytu (`first_night_on`, `nights`) przyciętego do okna.
 *
 * ⚠️ **Liczba zapytań nie rośnie z liczbą dób ani stanowisk:** stanowiska z góry wczytują sloty,
 * blokady i grupy, rezerwacje okna — jednym zapytaniem z wędkarzem i warunkami. Instancja żyje
 * jedno żądanie (odczyt w obrębie pytania, nie bufor werdyktu — `dostepnosc.md` §2).
 */
final class ReservationCalendar
{
    /** Najdłuższy pobyt proponowany z „wolne do …" na telefonie — stała w jednym miejscu (042). */
    public const MAX_FREE_NIGHTS = 30;

    /** @var array<int, PositionAvailability> */
    private array $availability = [];

    private ?FishingDayCalendar $calendar = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * @param  int|null  $groupId  tylko stanowiska z grupy
     * @param  string|null  $status  `pending` · `confirmed` · `paid` · `null` = wszystkie aktywne
     */
    public function grid(CarbonImmutable $from, int $dayCount, ?int $groupId = null, ?string $status = null, ?CarbonImmutable $now = null): ?ReservationCalendarGrid
    {
        $timezone = $this->fishery->timezoneName();
        $first = CarbonImmutable::parse($from->toDateString(), $timezone)->startOfDay();
        $days = [];

        for ($offset = 0; $offset < $dayCount; $offset++) {
            $days[] = $first->addDays($offset);
        }

        $firstNight = $this->calendar()->dayStartingOn($days[0]);
        $lastNight = $this->calendar()->dayStartingOn($days[count($days) - 1]);

        if (! $firstNight instanceof FishingDay || ! $lastNight instanceof FishingDay) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $today = $now->setTimezone($timezone)->startOfDay();
        $positions = $this->positions($groupId);
        $reservations = $this->reservations($firstNight->startsAt, $lastNight->endsAt, $status)->groupBy('position_id');
        $rows = [];

        foreach ($positions as $position) {
            $availability = $this->availabilityOf($position);
            $cells = [];

            foreach ($days as $index => $day) {
                $night = $this->calendar()->dayStartingOn($day);
                $cells[$index] = new ReservationCalendarCell(
                    day: $day,
                    past: $day < $today,
                    occupied: $night !== null && $availability->isOccupied($night),
                    blocked: $night !== null && $availability->isBlocked($night),
                );
            }

            $bars = [];

            foreach ($reservations->get($position->getKey(), []) as $reservation) {
                $bars[] = $this->barFor($reservation, $first, count($days), $now);
            }

            usort($bars, fn (ReservationCalendarBar $a, ReservationCalendarBar $b): int => $a->column <=> $b->column);

            $rows[] = new ReservationCalendarRow($position, $cells, $bars, $position->status === PositionStatus::Withdrawn);
        }

        return new ReservationCalendarGrid($days, $rows, $today);
    }

    /**
     * Liczniki nad siatką: przyjazdy i wyjazdy dziś (w strefie łowiska), niepotwierdzone, do kontaktu.
     *
     * @return array{arrivals: int, departures: int, unconfirmed: int, to_contact: int}
     */
    public function counters(?CarbonImmutable $now = null): array
    {
        $timezone = $this->fishery->timezoneName();
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($timezone)->startOfDay();
        $dayStart = $today->utc()->format('Y-m-d H:i:s');
        $dayEnd = $today->addDay()->utc()->format('Y-m-d H:i:s');

        $active = fn () => $this->fishery->reservations()->active();

        return [
            'arrivals' => $active()->where('first_night_on', $today->toDateString())->count(),
            'departures' => $active()->where('ends_at', '>=', $dayStart)->where('ends_at', '<', $dayEnd)->count(),
            'unconfirmed' => $active()->where('status', ReservationStatus::Pending->value)->count(),
            'to_contact' => $this->fishery->reservations()->toContact()->count(),
        ];
    }

    /**
     * Ile kolejnych dób od `$date` jest wolnych na stanowisku — do następnej rezerwacji albo blokady,
     * nie więcej niż `MAX_FREE_NIGHTS`. Te same pytania co ścieżka zapisu (`PositionAvailability`).
     */
    public function freeNightsFrom(Position $position, CarbonImmutable $date): int
    {
        $availability = $this->availabilityOf($position);
        $free = 0;

        for ($offset = 0; $offset < self::MAX_FREE_NIGHTS; $offset++) {
            $night = $this->calendar()->dayStartingOn($date->addDays($offset));

            if (! $night instanceof FishingDay || $availability->isOccupied($night) || $availability->isBlocked($night)) {
                break;
            }

            $free++;
        }

        return $free;
    }

    /**
     * @return Collection<int, Position>
     */
    private function positions(?int $groupId): Collection
    {
        return $this->fishery->positions()
            ->when($groupId !== null, fn ($query) => $query->whereHas('groups', fn ($groups) => $groups->whereKey($groupId)))
            ->with(['reservationSlots', 'availabilityBlocks.attribute'])
            ->orderBy('name')
            ->get()
            ->each(fn (Position $position) => $position->setRelation('fishery', $this->fishery));
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function reservations(CarbonImmutable $from, CarbonImmutable $to, ?string $status): Collection
    {
        return $this->fishery->reservations()
            ->active()
            ->where('starts_at', '<', $to->utc()->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $from->utc()->format('Y-m-d H:i:s'))
            ->when($status === 'pending', fn ($query) => $query->where('status', ReservationStatus::Pending->value))
            ->when($status === 'confirmed', fn ($query) => $query->where('status', ReservationStatus::Confirmed->value))
            ->when($status === 'paid', fn ($query) => $query->where('payment_status', PaymentStatus::Paid->value))
            ->with(['angler.user', 'terms'])
            ->get()
            ->each(fn (Reservation $reservation) => $reservation->setRelation('fishery', $this->fishery));
    }

    private function barFor(Reservation $reservation, CarbonImmutable $first, int $dayCount, CarbonImmutable $now): ReservationCalendarBar
    {
        $start = (int) $first->diffInDays(CarbonImmutable::parse($reservation->first_night_on->toDateString(), $first->timezone), false);
        $end = $start + $reservation->nights - 1;
        $column = max(0, $start);
        $last = min($dayCount - 1, $end);

        return new ReservationCalendarBar(
            reservation: $reservation,
            column: $column,
            span: max(1, $last - $column + 1),
            startsBefore: $start < 0,
            endsAfter: $end > $dayCount - 1,
            kind: match (true) {
                $reservation->isInProgress($now) => 'in_progress',
                $reservation->status === ReservationStatus::Pending => 'pending',
                $reservation->payment_status === PaymentStatus::Paid => 'paid',
                default => 'confirmed',
            },
        );
    }

    private function availabilityOf(Position $position): PositionAvailability
    {
        return $this->availability[(int) $position->getKey()] ??= new PositionAvailability($position);
    }

    private function calendar(): FishingDayCalendar
    {
        return $this->calendar ??= new FishingDayCalendar($this->fishery);
    }
}
