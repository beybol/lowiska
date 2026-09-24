<?php

namespace App\Services;

use App\Models\Fishery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Doby tygodnia identyfikowane DNIEM ROZPOCZĘCIA (ISO 1–7) — jedyny dom tej wiedzy (zadanie 023).
 *
 * Z tego samego rodzaju wartości korzystają dwa ekrany: weekend sprzedawany w całości
 * („Reguły sprzedaży", `weekend_days`) i warunek dopłaty („Cennik", `weekdays`). Nazwa dnia,
 * przedział „dzień → następny dzień", godziny doby, podsumowanie zbioru i skrót do nagłówka
 * mieszkały wcześniej osobno w obu stronach — drugi literał tej samej logiki to defekt.
 *
 * ⚠️ **Doba, nie dzień.** Etykieta pokazuje PRZEDZIAŁ („pt → sob · 15:00 → 15:00"), bo przy samych
 * nazwach dni operator zaznacza „piątek, sobotę i niedzielę" dla weekendu, który składa się
 * z DWÓCH dób (`panel-wlasciciela.md` §6).
 *
 * ⚠️ **Bez godzin doby klasa nadal działa** — dopłata wybiera doby po dniu rozpoczęcia, więc
 * godzin do tego nie potrzebuje. Znika wtedy wyłącznie linia godzin.
 */
final class WeekdayNights
{
    public function __construct(
        private readonly ?string $startTime = null,
        private readonly ?string $endTime = null,
    ) {}

    public static function forFishery(Fishery $fishery): self
    {
        return new self(
            self::time($fishery->day_start_time),
            self::time($fishery->day_end_time),
        );
    }

    public function hasHours(): bool
    {
        return $this->startTime !== null && $this->endTime !== null;
    }

    /** Skrócona nazwa dnia w bieżącym locale: „pt". */
    public static function dayName(int $isoDay): string
    {
        return CarbonImmutable::now()
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->addDays($isoDay - 1)
            ->locale(app()->getLocale())
            ->isoFormat('ddd');
    }

    /** Doba jako przedział dni: „pt → sob". */
    public function range(int $isoDay): string
    {
        return self::dayName($isoDay).' → '.self::dayName(self::next($isoDay));
    }

    /** Godziny doby: „15:00 → 15:00" albo `null`, gdy łowisko ich nie ma. */
    public function hours(): ?string
    {
        return $this->hasHours() ? $this->startTime.' → '.$this->endTime : null;
    }

    /**
     * Siedem opcji dla `ToggleButtons` — etykiety dwuwierszowe (przedział + godziny).
     *
     * ⚠️ **Etykieta jest HTML-em, więc KAŻDA wartość przechodzi przez `e()`.** Nazwy dni pochodzą
     * z locale, a godziny z bazy — żadna nie trafia do znacznika surowa.
     *
     * @return array<int, Htmlable>
     */
    public function options(): array
    {
        $options = [];
        $hours = $this->hours();

        foreach (range(1, 7) as $isoDay) {
            $html = '<span style="display:block; font-weight:600">'.e($this->range($isoDay)).'</span>';

            if ($hours !== null) {
                $html .= '<span style="display:block; font-size:.75em; font-weight:400; opacity:.75">'.e($hours).'</span>';
            }

            $options[$isoDay] = new HtmlString($html);
        }

        return $options;
    }

    /**
     * Podsumowanie zbioru: „Od pt 15:00 do nd 15:00 · 2 doby".
     *
     * ⚠️ **Zbiór rozkłada się na MAKSYMALNE CIĄGI CYKLICZNE** — dopłata nie musi być ciągła, więc
     * „pn + śr" to dwa odcinki, a `{7, 1}` to jeden (nd → wt). Weekend jest zawsze jednym ciągiem
     * (pilnuje tego `WeekendDaysAreContiguous`) i wygląda tak jak przed wydzieleniem tej klasy.
     *
     * @param  string  $whenEmpty  tekst dla pustego zbioru — jego znaczenie zależy od ekranu
     */
    public function summary(mixed $days, string $whenEmpty): string
    {
        $set = self::normalize($days);

        if ($set === []) {
            return $whenEmpty;
        }

        $count = trans_choice(':count night|:count nights', count($set), ['count' => count($set)]);

        // Wszystkie siedem dób nie ma początku ani końca — zostaje sama liczba.
        if (count($set) === 7) {
            return $count;
        }

        $parts = array_map(fn (array $run): string => $this->runText($run), self::runs($set));

        return implode('; ', $parts).' · '.$count;
    }

    /**
     * Krótka forma do nagłówka wiersza: „pt→nd" albo „pn, śr".
     *
     * ⚠️ Zastępuje dawne „pierwszy–ostatni", które przekłamywało zbiór nieciągły.
     */
    public function shortForm(mixed $days): string
    {
        $set = self::normalize($days);

        if ($set === []) {
            return '';
        }

        if (count($set) === 7) {
            return trans_choice(':count night|:count nights', 7, ['count' => 7]);
        }

        return implode(', ', array_map(
            static fn (array $run): string => count($run) === 1
                ? self::dayName($run[0])
                : self::dayName($run[0]).'→'.self::dayName(self::next($run[count($run) - 1])),
            self::runs($set),
        ));
    }

    /**
     * Maksymalne ciągi cykliczne zbioru, każdy w kolejności dób, uporządkowane po pierwszej dobie.
     *
     * ⚠️ Pierwsza doba ciągu to ta, której poprzednik do zbioru NIE należy — przy zbiorze
     * cyklicznym (`{7, 1}`) nie da się tego wziąć z `min()`.
     *
     * @param  array<int, int>  $set  znormalizowany, niepełny zbiór
     * @return array<int, array<int, int>>
     */
    public static function runs(array $set): array
    {
        $runs = [];

        foreach ($set as $day) {
            if (in_array(self::previous($day), $set, true)) {
                continue;
            }

            $run = [$day];
            $current = $day;

            while (in_array(self::next($current), $set, true) && count($run) < 7) {
                $current = self::next($current);
                $run[] = $current;
            }

            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * @param  array<int, int>  $run
     */
    private function runText(array $run): string
    {
        $startDay = self::dayName($run[0]);
        $endDay = self::dayName(self::next($run[count($run) - 1]));

        if (! $this->hasHours()) {
            return __('From :startDay to :endDay', ['startDay' => $startDay, 'endDay' => $endDay]);
        }

        return __('From :startDay :startTime to :endDay :endTime', [
            'startDay' => $startDay,
            'startTime' => $this->startTime,
            'endDay' => $endDay,
            'endTime' => $this->endTime,
        ]);
    }

    /**
     * Zbiór jako posortowane, unikalne liczby 1–7. Stan formularza przysyła łańcuchy.
     *
     * @return array<int, int>
     */
    private static function normalize(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $set = array_values(array_unique(array_filter(
            array_map(static fn (mixed $day): int => (int) $day, $days),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        sort($set);

        return $set;
    }

    private static function next(int $isoDay): int
    {
        return $isoDay === 7 ? 1 : $isoDay + 1;
    }

    private static function previous(int $isoDay): int
    {
        return $isoDay === 1 ? 7 : $isoDay - 1;
    }

    private static function time(mixed $time): ?string
    {
        return blank($time) ? null : substr((string) $time, 0, 5);
    }
}
