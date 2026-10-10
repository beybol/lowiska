<?php

namespace Tests\Support;

use App\Enums\ReservationSource;
use App\Enums\ServiceScope;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AnglerDetails;
use App\Services\BookingOutcome;
use App\Services\BookingRequest;
use App\Services\ReservationBooking;
use LogicException;

/**
 * Wspólne przygotowanie danych dla testów rezerwacji (zadania 039–046).
 *
 * ⚠️ Klasa z autoloadera, nie funkcje Pesta — z tego samego powodu co `StayFixtures`: `--filter`
 * na jednym pliku nie gubi pomocników, a `tests/Pest.php` (wyzwalacz T3) zostaje nietknięty.
 *
 * Rezerwacje zapisuje WYŁĄCZNIE `ReservationBooking` — także w testach, bo zapis z pominięciem
 * serwisu nie tworzy slotów ani warunków i testowałby stan, którego aplikacja nie wytwarza.
 */
final class ReservationFixtures
{
    /**
     * Łowisko z dobą 15:00 → 15:00, sezonem 2026, stawką 70 zł i stanowiskiem w sprzedaży.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Fishery, 1: Position, 2: User}
     */
    public static function bookableFishery(array $attributes = [], float $rate = 70.00): array
    {
        [$fishery, $position, $owner] = StayFixtures::fisheryWithPosition($attributes);
        StayFixtures::rate($fishery, $rate);

        return [$fishery->fresh(), $position->fresh(), $owner];
    }

    /**
     * @param  array<string, mixed>  $overrides  nazwane argumenty `BookingRequest`
     */
    public static function request(Position $position, string $startsOn, int $nights = 1, array $overrides = []): BookingRequest
    {
        return new BookingRequest(...array_merge([
            'position' => $position->fresh(),
            'startsOn' => $startsOn,
            'nights' => $nights,
            'angler' => new AnglerDetails('Jan Kowalski', 'jan@example.com', '+48500100200'),
            'source' => ReservationSource::Operator,
            'actor' => $position->fishery?->user,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function attempt(Position $position, string $startsOn, int $nights = 1, array $overrides = []): BookingOutcome
    {
        return (new ReservationBooking)->book(self::request($position, $startsOn, $nights, $overrides));
    }

    /**
     * Rezerwacja, która MUSI się udać — inaczej test przygotowuje zły stan.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function book(Position $position, string $startsOn, int $nights = 1, array $overrides = []): Reservation
    {
        $outcome = self::attempt($position, $startsOn, $nights, $overrides);

        if (! $outcome->succeeded || $outcome->reservation === null) {
            throw new LogicException('Fixture booking failed: '.$outcome->message());
        }

        return $outcome->reservation;
    }

    /**
     * Usługa przypięta do stanowiska.
     *
     * @param  array<string, mixed>  $state
     */
    public static function service(Fishery $fishery, Position $position, array $state = [], bool $required = false): AdditionalService
    {
        $service = AdditionalService::factory()->create(array_merge([
            'fishery_id' => $fishery->id,
            'is_active' => true,
            'scope' => ServiceScope::SelectedPositions->value,
            'price' => '20.00',
            'available_count' => null,
        ], $state));

        $position->additionalServices()->attach($service->id, ['is_required' => $required]);

        return $service;
    }
}
