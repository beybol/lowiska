<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PositionStatus;
use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Model siatki warstwy „Rezerwacje" kalendarza (zadanie 042, zasada G4) — widok niczego nie liczy.
 *
 * ⚠️ **Zajętość i blokady pochodzą z `PositionAvailability`** (`isOccupied()`, `isBlocked()`) — tego
 * samego źródła, które sprawdza ścieżka zapisu. Siatka nie ma własnej reguły nakładania.
 *
 * **Kolumna = dzień kalendarzowy z dwiema połowami** (model połówek): pierwsza połowa należy do doby
 * zaczętej dzień wcześniej, druga — do doby od tego dnia. Pasek rezerwacji biegnie od połowy dnia
 * przyjazdu do połowy dnia wyjazdu (`first_night_on` + `nights`), przycięty do okna; dzień wyjazdu jednej
 * i przyjazdu drugiej rezerwacji to jedna komórka z dwoma ukośnymi końcami.
 *
 * ⚠️ **Liczba zapytań nie rośnie z liczbą dób ani stanowisk:** stanowiska z góry wczytują sloty
 * (wyłącznie od północy pierwszego dnia okna — wiersze nie rosną z historią, zadanie 047 G1), blokady i grupy,
 * rezerwacje okna — jednym zapytaniem z wędkarzem i warunkami. Instancja żyje jedno żądanie (odczyt
 * w obrębie pytania, nie bufor werdyktu — `dostepnosc.md` §2).
 *
 * Oferta w pustych dobach (047, A3) przychodzi z zewnątrz jako siatka `SaleCalendar` — ta klasa tylko
 * przypina komórkę oferty do doby; o cenie i sprzedawalności nie rozstrzyga.
 *
 * Filtry (licznik, status, „wymagają reakcji") nie usuwają pasków, tylko przygaszają niepasujące
 * (`ReservationCalendarBar::dimmed`) — zajęta doba bez paska wyglądałaby na wolną.
 */
final class ReservationCalendar
{
    /** Najdłuższy pobyt proponowany z „wolne do …" na telefonie — stała w jednym miejscu (042). */
    public const MAX_FREE_NIGHTS = 30;

    /** Filtry nad siatką: status z listy i liczniki-filtry (047, A8). */
    public const FILTERS = ['pending', 'confirmed', 'paid', 'arrivals', 'departures', 'expiring', 'to_contact', 'attention'];

    /** @var array<int, PositionAvailability> */
    private array $availability = [];

    private ?FishingDayCalendar $calendar = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * @param  int|null  $groupId  tylko stanowiska z grupy
     * @param  string|null  $filter  jeden z `FILTERS` — przygasza niepasujące paski; `null` = bez filtra
     * @param  SaleCalendar|null  $offer  cena w pustych dobach: warstwa oferty, pytana WYŁĄCZNIE o doby wolne i nieminione —
     *                                    te, w których komórka pokazuje cenę (051); `null` = bez ceny
     */
    public function grid(CarbonImmutable $from, int $dayCount, ?int $groupId = null, ?string $filter = null, ?CarbonImmutable $now = null, ?SaleCalendar $offer = null): ?ReservationCalendarGrid
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
        // Od północy pierwszego dnia: jego pierwsza połowa należy do doby zaczętej dzień wcześniej.
        $positions = $this->positions($groupId, $first);
        $reservations = $this->reservations($first, $lastNight->endsAt)->groupBy('position_id');
        $filter = in_array($filter, self::FILTERS, true) ? $filter : null;
        $rows = [];

        foreach ($positions as $position) {
            $availability = $this->availabilityOf($position);
            $cells = [];
            $nightBlocks = [];
            $nights = [];
            $freeDays = [];

            $previous = $this->calendar()->dayStartingOn($first->subDay());
            $previousOccupied = $previous !== null && $availability->isOccupied($previous);
            $previousBlock = $previous === null ? null : $availability->saleBlocksOn($previous)->first();

            foreach ($days as $index => $day) {
                $night = $this->calendar()->dayStartingOn($day);
                $occupied = $night !== null && $availability->isOccupied($night);
                $past = $day < $today;
                // Blokada obejmuje doby od „od" do „do" (przełom dnia jak w rezerwacji, `dostepnosc.md` §3).
                $blocks = $night === null ? collect() : $availability->saleBlocksOn($night);
                $nightBlocks[$index] = $blocks->first();
                $nights[$index] = [$occupied, $past, $blocks->isNotEmpty(), $previousOccupied];
                $previousOccupied = $occupied;

                if (! $occupied && ! $past) {
                    $freeDays[] = $day;
                }
            }

            // Oferta tylko tam, gdzie komórka ją pokazuje: doby wolne i nieminione (051). Zajęte i minione dostają `null`.
            $positionOffer = $offer?->offerCellsFor($position, $freeDays) ?? [];

            foreach ($days as $index => $day) {
                [$occupied, $past, $blocked, $morningOccupied] = $nights[$index];
                $cells[$index] = new ReservationCalendarCell(
                    day: $day,
                    past: $past,
                    occupied: $occupied,
                    blocked: $blocked,
                    offer: $positionOffer[$day->toDateString()] ?? null,
                    morningOccupied: $morningOccupied,
                );
            }

            $bars = [];

            foreach ($reservations->get($position->getKey(), []) as $reservation) {
                $bars[] = $this->barFor($reservation, $first, count($days), $now, $filter !== null && ! $this->matches($reservation, $filter, $today, $now));
            }

            usort($bars, fn (ReservationCalendarBar $a, ReservationCalendarBar $b): int => $a->column <=> $b->column);

            $rows[] = new ReservationCalendarRow(
                $position,
                $cells,
                $bars,
                $position->status === PositionStatus::Withdrawn,
                $this->blockBars($nightBlocks, $bars, $first, count($days), $previousBlock),
            );
        }

        return new ReservationCalendarGrid($days, $rows, $today);
    }

    /**
     * Liczniki nad siatką: przyjazdy i wyjazdy dziś (w strefie łowiska), niepotwierdzone, do kontaktu.
     *
     * @return array{arrivals: int, departures: int, unconfirmed: int, expiring: int, to_contact: int}
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
            'expiring' => $this->fishery->reservations()->expiringWithin(24)->count(),
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
    private function positions(?int $groupId, CarbonImmutable $windowStart): Collection
    {
        return $this->fishery->positions()
            ->when($groupId !== null, fn ($query) => $query->whereHas('groups', fn ($groups) => $groups->whereKey($groupId)))
            // Sloty od początku okna (047, G1): `freeNightsFrom()` patrzy tylko w przód, więc historia
            // nie jest potrzebna, a bez dolnej granicy wiersz rósłby z każdą rezerwacją w przeszłości.
            ->with([
                'reservationSlots' => fn ($query) => $query->where('ends_at', '>', $windowStart->utc()->format('Y-m-d H:i:s')),
                // Blokady — nie relacją: `PositionAvailability` czyta je z magazynu odczytów na żądanie (ADR-029).
            ])
            ->orderBy('name')
            ->get()
            ->each(fn (Position $position) => $position->setRelation('fishery', $this->fishery));
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function reservations(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->fishery->reservations()
            ->active()
            ->where('starts_at', '<', $to->utc()->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $from->utc()->format('Y-m-d H:i:s'))
            ->with(['angler.user', 'terms'])
            ->get()
            ->each(fn (Reservation $reservation) => $reservation->setRelation('fishery', $this->fishery));
    }

    /**
     * Czy rezerwacja pasuje do filtra nad siatką — z kolumn już wczytanych, bez zapytań.
     */
    private function matches(Reservation $reservation, string $filter, CarbonImmutable $today, CarbonImmutable $now): bool
    {
        $pending = $reservation->status === ReservationStatus::Pending;

        return match ($filter) {
            'pending' => $pending,
            'confirmed' => $reservation->status === ReservationStatus::Confirmed,
            'paid' => $reservation->payment_status === PaymentStatus::Paid,
            'arrivals' => $reservation->first_night_on->toDateString() === $today->toDateString(),
            'departures' => $reservation->ends_at->setTimezone($today->timezone)->toDateString() === $today->toDateString(),
            'expiring' => $pending && $reservation->confirm_by !== null && $reservation->confirm_by <= $now->addHours(24),
            'to_contact' => $reservation->needsContact(),
            'attention' => $pending || $reservation->needsContact(),
            default => true,
        };
    }

    /**
     * Paski blokad w modelu połówek: kolejne doby w TEJ SAMEJ blokadzie, z pominięciem dób pobytu
     * rezerwacji (zostawionej przy blokadzie); pasek sięga połowy dnia po ostatniej dobie.
     *
     * @param  array<int, AvailabilityBlock|null>  $nightBlocks  kolumna → pierwsza blokada przecinająca dobę
     * @param  array<int, ReservationCalendarBar>  $bars
     * @param  AvailabilityBlock|null  $previousBlock  blokada doby sprzed okna — jej koniec to ranek pierwszego dnia
     * @return array<int, ReservationCalendarBlock>
     */
    private function blockBars(array $nightBlocks, array $bars, CarbonImmutable $first, int $dayCount, ?AvailabilityBlock $previousBlock = null): array
    {
        $underBar = [];

        foreach ($bars as $bar) {
            for ($column = $bar->column; $column < $bar->column + $bar->span; $column++) {
                if ($bar->coversNight($column)) {
                    $underBar[$column] = true;
                }
            }
        }

        $result = [];
        $column = 0;

        // Blokada kończąca się rano pierwszego dnia okna — sam trójkąt wyjazdu.
        if ($previousBlock !== null && ! ($nightBlocks[0] ?? null)?->is($previousBlock)) {
            $result[] = new ReservationCalendarBlock(block: $previousBlock, column: 0, span: 1, startsBefore: true, endsAfter: false);
        }

        while ($column < $dayCount) {
            $block = isset($underBar[$column]) ? null : ($nightBlocks[$column] ?? null);

            if ($block === null) {
                $column++;

                continue;
            }

            $start = $column;

            while ($column + 1 < $dayCount && ! isset($underBar[$column + 1]) && ($nightBlocks[$column + 1] ?? null)?->is($block)) {
                $column++;
            }

            $timezone = $first->timezone;
            $blockFirst = CarbonImmutable::parse($block->starts_on->toDateString(), $timezone);
            $blockLast = $block->ends_on === null ? null : CarbonImmutable::parse($block->ends_on->toDateString(), $timezone);

            $endsAfter = $column === $dayCount - 1 && ($blockLast === null || $blockLast > $first->addDays($dayCount - 1));

            $result[] = new ReservationCalendarBlock(
                block: $block,
                column: $start,
                // Ostatnia doba w kolumnie `$column` — pasek sięga dnia następnego, przycięty do okna.
                span: min($dayCount - 1, $column + 1) - $start + 1,
                startsBefore: $start === 0 && $blockFirst < $first,
                endsAfter: $endsAfter,
            );
            $column++;
        }

        return $result;
    }

    private function barFor(Reservation $reservation, CarbonImmutable $first, int $dayCount, CarbonImmutable $now, bool $dimmed = false): ReservationCalendarBar
    {
        // Model połówek: pasek od dnia przyjazdu do dnia WYJAZDU (pierwsza połowa) włącznie.
        $start = (int) $first->diffInDays(CarbonImmutable::parse($reservation->first_night_on->toDateString(), $first->timezone), false);
        $departure = $start + $reservation->nights;
        $column = max(0, $start);
        $last = min($dayCount - 1, $departure);

        return new ReservationCalendarBar(
            reservation: $reservation,
            column: $column,
            span: max(1, $last - $column + 1),
            startsBefore: $start < 0,
            endsAfter: $departure > $dayCount - 1,
            kind: match (true) {
                $reservation->isInProgress($now) => 'in_progress',
                $reservation->status === ReservationStatus::Pending => 'pending',
                $reservation->payment_status === PaymentStatus::Paid => 'paid',
                default => 'confirmed',
            },
            dimmed: $dimmed,
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
