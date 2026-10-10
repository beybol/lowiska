<?php

namespace App\Services;

use App\Enums\ServiceBillingUnit;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Wycena usług dodatkowych dla pobytu (zadanie 039; ADR-026 pkt 9, aktualizacja ADR-015).
 *
 * ⚠️ Osobna usługa SKŁADANA przez `StayOffer`, tak jak sprzedawalność i cennik pobytu — warstwa
 * oferty nadal nie liczy niczego własnego. Które usługi stanowisko ma i czy są dostępne, mówi
 * `PositionServices`; ile egzemplarzy zostało — `ServiceStock`; rachunek jednostki — `StayServiceLine`.
 *
 * ⚠️ Usługa obowiązkowa trafia do wyceny z liczbą co najmniej 1, także gdy wołający jej nie podał.
 * Usługi nie wchodzą do podstawy obniżki przedsprzedażowej (`cennik.md` §4).
 */
final class StayServicePricing
{
    private ?PositionServices $services = null;

    public function __construct(private readonly Position $position)
    {
        if ($position->fishery === null) {
            throw new InvalidArgumentException(
                "Position {$position->id} has no fishery, so its services are undefined."
            );
        }
    }

    /**
     * @param  array<int, int>  $quantities  id usługi → liczba egzemplarzy
     * @param  int|null  $excludingReservationId  rezerwacja zmieniana — jej egzemplarze nie zajmują
     */
    public function quote(
        CarbonInterface|string $startsOn,
        int $nights,
        array $quantities = [],
        ?int $excludingReservationId = null,
    ): StayServicesQuote {
        $fishery = $this->position->fishery;
        $calendar = new FishingDayCalendar($fishery);
        $first = CarbonImmutable::parse($startsOn, $fishery->timezoneName())->startOfDay();
        $days = [];

        for ($offset = 0; $offset < max(1, $nights); $offset++) {
            $day = $calendar->dayStartingOn($first->addDays($offset));

            if (! $day instanceof FishingDay) {
                return new StayServicesQuote([], array_keys(array_filter($quantities)));
            }

            $days[] = $day;
        }

        $lines = [];
        $offered = [];

        foreach ($this->services()->forNights($this->position, $days[0], $days[count($days) - 1]) as $status) {
            $service = $status->service;
            $id = (int) $service->getKey();
            $offered[$id] = true;
            $quantity = max(0, (int) ($quantities[$id] ?? 0));

            if ($status->isRequired && $status->isAvailable()) {
                $quantity = max(1, $quantity);
            }

            $lines[] = new StayServiceLine(
                service: $service,
                isRequired: $status->isRequired,
                problems: $status->problems,
                unit: $service->billing_unit ?? ServiceBillingUnit::PerNight,
                quantity: $quantity,
                nights: count($days),
                unitPriceInCents: $service->priceInCents() ?? 0,
                availableCount: $service->available_count === null ? null : (int) $service->available_count,
                remaining: ServiceStock::remaining($service, $days, $excludingReservationId),
            );
        }

        $unknown = array_values(array_filter(
            array_map('intval', array_keys(array_filter($quantities, fn ($quantity): bool => (int) $quantity > 0))),
            fn (int $id): bool => ! isset($offered[$id]),
        ));

        return new StayServicesQuote($lines, $unknown);
    }

    private function services(): PositionServices
    {
        return $this->services ??= new PositionServices($this->position->fishery);
    }
}
