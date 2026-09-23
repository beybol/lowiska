<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Jednostka okna kalendarza podglądowego (zadanie 019).
 *
 * ⚠️ **Okno jest jednostką KALENDARZOWĄ, nie liczbą dób.** Pokazujemy miesiąc albo tydzień,
 * a nie „trzydzieści dni od kotwicy": kolumny wypadają wtedy tam, gdzie operator ich szuka,
 * i da się powiedzieć „czerwiec" zamiast „1–30.06".
 *
 * ⚠️ **Dwie wartości i ani jednej więcej — to świadome zamknięcie najgorszego przypadku.**
 * Koszt renderu rośnie z iloczynem dób i stanowisk, a dopiero go mierzymy. Kwartał czy rok
 * dołożyłby kolumn dokładnie tam, gdzie nie wiemy jeszcze, ile kosztują.
 */
enum CalendarWindow: string
{
    case Month = 'month';

    case Week = 'week';

    public function label(): string
    {
        return match ($this) {
            self::Month => __('Month'),
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

    /** Początek jednostki ZAWIERAJĄCEJ tę datę — nie sama data. */
    public function startFor(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $day->startOfMonth()->startOfDay(),
            self::Week => $day->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
        };
    }

    public function endFor(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $day->endOfMonth()->startOfDay(),
            self::Week => $this->startFor($day)->addDays(6),
        };
    }

    public function next(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $this->startFor($day)->addMonth(),
            self::Week => $this->startFor($day)->addWeek(),
        };
    }

    public function previous(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Month => $this->startFor($day)->subMonth(),
            self::Week => $this->startFor($day)->subWeek(),
        };
    }
}
