<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Jednostka okna kalendarza (zadania 019, 042).
 *
 * ⚠️ **Okno jest jednostką KALENDARZOWĄ, nie liczbą dób.** Pokazujemy miesiąc, tydzień albo dwa
 * tygodnie od poniedziałku, a nie „trzydzieści dni od kotwicy": kolumny wypadają wtedy tam, gdzie
 * operator ich szuka.
 *
 * ⚠️ **Zbiór wartości jest świadomie zamknięty** — koszt renderu rośnie z iloczynem dób i stanowisk.
 * „2 tygodnie" doszło z warstwą „Rezerwacje" (042, Q7); **miesiąc jest wyłącznie w warstwie „Oferta"**
 * (`optionsFor()`), bo paski rezerwacji w miesiącu przestają być czytelne.
 */
enum CalendarWindow: string
{
    case Month = 'month';

    case TwoWeeks = 'two_weeks';

    case Week = 'week';

    public function label(): string
    {
        return match ($this) {
            self::Month => __('Month'),
            self::TwoWeeks => __('2 weeks'),
            self::Week => __('Week'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Okna dostępne w warstwie kalendarza — w „Rezerwacjach" bez miesiąca.
     *
     * @return array<string, string>
     */
    public static function optionsFor(CalendarLayer $layer): array
    {
        return array_filter(
            self::options(),
            fn (string $value): bool => $layer === CalendarLayer::Offer || $value !== self::Month->value,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Początek jednostki ZAWIERAJĄCEJ tę datę — nie sama data. */
    public function startFor(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $day->startOfMonth()->startOfDay(),
            self::TwoWeeks, self::Week => $day->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
        };
    }

    public function endFor(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $day->endOfMonth()->startOfDay(),
            self::TwoWeeks => $this->startFor($day)->addDays(13),
            self::Week => $this->startFor($day)->addDays(6),
        };
    }

    public function next(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $this->startFor($day)->addMonth(),
            // „2 tygodnie" przesuwa się o tydzień — drugi tydzień zostaje na ekranie jako kontekst.
            self::TwoWeeks, self::Week => $this->startFor($day)->addWeek(),
        };
    }

    public function previous(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $this->startFor($day)->subMonth(),
            self::TwoWeeks, self::Week => $this->startFor($day)->subWeek(),
        };
    }
}
