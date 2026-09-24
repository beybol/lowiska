<?php

namespace App\Services;

use App\Models\Fishery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * JEDYNE miejsce odpowiadające na pytanie „jaki procent zwrotu przy odwołaniu w chwili D pobytu
 * zaczynającego się dobą S" (zadanie 021, M7).
 *
 * ⚠️ **Dotyczy wyłącznie odwołania przez WĘDKARZA.** Odwołanie przez łowisko (blokada na sprzedany
 * termin, G8) to zawsze pełny zwrot i nie przechodzi przez progi — tego nie da się skonfigurować.
 *
 * Reguły (rozstrzygnięcia zadania 021):
 * - „N dni przed" to różnica DAT KALENDARZOWYCH w strefie łowiska między dniem rozpoczęcia
 *   pierwszej doby a dniem odwołania; godziny doby nie grają roli;
 * - granica jest DOMKNIĘTA: próg „N dni" obowiązuje, gdy różnica wynosi co najmniej N; odwołanie
 *   wcześniejsze niż najdalszy próg podlega najdalszemu, bliżej niż najbliższy — daje 0%;
 * - odwołać można wyłącznie PRZED rozpoczęciem pierwszej doby — później to przerwanie pobytu;
 * - brak progów to „polityka nieustawiona", a nie 0% ani 100%.
 *
 * ⚠️ Procent liczy się od całej zapłaconej kwoty, ze wszystkimi usługami — samo liczenie kwoty
 * powstaje z płatnościami (D1, D7). Konfiguracja jest bieżąca; zamrożenie w chwili zakupu robi
 * snapshot transakcji (G1, Z1).
 */
final class RefundPolicy
{
    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Progi od najdalszego do najbliższego.
     *
     * @return array<int, array{days: int, percent: int}>
     */
    public function tiers(): array
    {
        return self::normalize($this->fishery->refund_policy);
    }

    public function isSet(): bool
    {
        return $this->tiers() !== [];
    }

    /**
     * „0% zawsze" — żaden próg nie zwraca więcej niż 0%. Warunek ROBOCZY ostrzeżenia w panelu:
     * właściwy należy do prawnika (TODO-3, pytanie 3).
     */
    public function refundsNothing(): bool
    {
        return self::refundsNothingIn($this->fishery->refund_policy);
    }

    /**
     * Ten sam warunek dla stanu formularza, zanim trafi do łowiska.
     */
    public static function refundsNothingIn(mixed $tiers): bool
    {
        $normalized = self::normalize($tiers);

        return $normalized !== [] && max(array_column($normalized, 'percent')) === 0;
    }

    public function forCancellation(CarbonInterface|string $firstNight, CarbonInterface $cancelledAt): RefundDecision
    {
        $timezone = $this->fishery->timezoneName();
        $stayStartsOn = CarbonImmutable::parse($firstNight, $timezone)->startOfDay();
        $cancelled = CarbonImmutable::instance($cancelledAt)->setTimezone($timezone);

        if ($cancelled >= $this->firstNightStartsAt($stayStartsOn)) {
            return RefundDecision::stayStarted();
        }

        // ⚠️ Różnica DAT, liczona w UTC: w strefie łowiska doba przy zmianie czasu ma 23 albo
        // 25 godzin, a `diffInDays()` na momentach dałby ułamek i uciął dzień.
        $daysBefore = (int) CarbonImmutable::parse($cancelled->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($stayStartsOn->toDateString(), 'UTC'), false);
        $tiers = $this->tiers();

        if ($tiers === []) {
            return RefundDecision::policyNotSet($daysBefore);
        }

        foreach ($tiers as $tier) {
            if ($daysBefore >= $tier['days']) {
                return RefundDecision::refund($tier['percent'], $daysBefore);
            }
        }

        return RefundDecision::refund(0, $daysBefore);
    }

    /**
     * Postać zapisywana i czytana: liczby całkowite, bez duplikatów liczby dni, od najdalszego progu.
     *
     * @return array<int, array{days: int, percent: int}>
     */
    public static function normalize(mixed $tiers): array
    {
        if (! is_array($tiers)) {
            return [];
        }

        $normalized = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_numeric($tier['days'] ?? null) || ! is_numeric($tier['percent'] ?? null)) {
                continue;
            }

            $normalized[(int) $tier['days']] = ['days' => (int) $tier['days'], 'percent' => (int) $tier['percent']];
        }

        krsort($normalized);

        return array_values($normalized);
    }

    /**
     * Chwila rozpoczęcia pierwszej doby — z `FishingDayCalendar`; bez godzin doby północ dnia
     * rozpoczęcia (łowisko bez godzin doby i tak nic nie sprzedaje).
     */
    private function firstNightStartsAt(CarbonImmutable $stayStartsOn): CarbonImmutable
    {
        $night = (new FishingDayCalendar($this->fishery))->dayStartingOn($stayStartsOn);

        return $night instanceof FishingDay ? $night->startsAt : $stayStartsOn;
    }
}
