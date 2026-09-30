<?php

namespace App\Services;

use App\Models\Fishery;
use App\Models\SalePeriod;
use App\Models\WholeTermPeriod;
use Carbon\CarbonImmutable;

/**
 * Wyciąg zasad sprzedaży łowiska — jedna linijka nad kalendarzem portalu (zadanie 033, portal-v3 §3.2).
 *
 * ⚠️ **JEDNA metoda dla trzech miejsc:** pełny wyciąg nad siatką, skrócony na telefonie i linijka
 * na karcie strony głównej biorą pozycje stąd (`items()`), różnią się tylko wyborem kluczy.
 *
 * ⚠️ **To opis konfiguracji, nie werdykt.** Wyciąg nie mówi, czy da się kupić pobyt — mówi, jakie
 * zasady łowisko ustawiło. Opis dób tygodnia ma jeden dom (`WeekdayNights`), a pusta wartość
 * (trzeci stan z 021) nie trafia do wyciągu.
 */
final class FisheryRulesSummary
{
    /** Ile dni przed otwarciem pokazujemy nadchodzącą przedsprzedaż (§3.2). */
    private const PRESALE_ANNOUNCE_DAYS = 30;

    /** Ile najbliższych terminów sprzedawanych w całości wypisujemy (§3.2). */
    private const WHOLE_TERMS_SHOWN = 2;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Pozycje wyciągu w kolejności z makiety — tylko wypełnione.
     *
     * @return array<string, string> klucz → tekst; klucze: day, weekend, whole_terms, season, min, max,
     *                               presale, licence, no_kill
     */
    public function items(): array
    {
        $fishery = $this->fishery;
        $today = CarbonImmutable::now($fishery->timezoneName())->startOfDay();
        $nights = WeekdayNights::forFishery($fishery);

        $weekend = (array) $fishery->weekend_days;
        $weekendText = null;

        if ($weekend !== [] && $nights->hasHours()) {
            $summary = explode(' · ', $nights->summary($weekend, ''))[0];
            $weekendText = __('weekend :range sold whole', ['range' => mb_strtolower($summary)]);
        }

        return array_filter([
            'day' => $nights->hasHours()
                ? __('Fishing day :from–:to', ['from' => $this->time($fishery->day_start_time), 'to' => $this->time($fishery->day_end_time)])
                : null,
            'weekend' => $weekendText,
            'whole_terms' => $this->wholeTerms($today),
            'season' => $this->season($today),
            'min' => (int) $fishery->min_nights > 1
                ? trans_choice('min. :count night|min. :count nights', (int) $fishery->min_nights, ['count' => (int) $fishery->min_nights])
                : null,
            'max' => $fishery->max_nights !== null
                ? trans_choice('max. :count night|max. :count nights', (int) $fishery->max_nights, ['count' => (int) $fishery->max_nights])
                : null,
            'presale' => $this->presale($today),
            'licence' => match ($fishery->fishing_license_required) {
                true => __('fishing licence required'),
                false => __('no fishing licence needed'),
                null => null,
            },
            'no_kill' => match ($fishery->no_kill) {
                true => __('fish are released (no-kill)'),
                false => __('fish may be taken'),
                null => null,
            },
        ], fn (?string $text): bool => $text !== null);
    }

    /**
     * Wyciąg jako jedna linijka — z wybranych kluczy (`null` = wszystkie).
     *
     * @param  array<int, string>|null  $keys
     */
    public function line(?array $keys = null): string
    {
        $items = $this->items();

        if ($keys !== null) {
            $items = array_filter($items, fn (string $key): bool => in_array($key, $keys, true), ARRAY_FILTER_USE_KEY);
        }

        $text = implode(' · ', $items);

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Linijka na karcie strony głównej (§5.1) i skrót na telefonie (§3.5). */
    public function cardLine(): string
    {
        return $this->line(['day', 'weekend', 'whole_terms', 'licence', 'no_kill']);
    }

    /**
     * Najbliższe NADCHODZĄCE terminy sprzedawane w całości, niezależnie od oglądanego tygodnia.
     * ⚠️ `last_day_on` wskazuje dzień rozpoczęcia OSTATNIEJ doby — koniec terminu to dzień później
     * (`dostepnosc.md` §4: granice święta znaczą co innego niż granice okresu sprzedaży).
     */
    private function wholeTerms(CarbonImmutable $today): ?string
    {
        $terms = $this->fishery->wholeTermPeriods()
            ->whereDate('last_day_on', '>=', $today->toDateString())
            ->orderBy('first_day_on')
            ->limit(self::WHOLE_TERMS_SHOWN)
            ->get();

        if ($terms->isEmpty()) {
            return null;
        }

        $ranges = $terms->map(fn (WholeTermPeriod $term): string => $term->first_day_on->format('d.m')
            .'–'.$term->last_day_on->copy()->addDay()->format('d.m'))->all();

        return __(':ranges sold whole', ['ranges' => implode(' '.__('and').' ', $ranges)]);
    }

    private function season(CarbonImmutable $today): ?string
    {
        $period = $this->fishery->salePeriods()
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->orderBy('starts_on')
            ->first();

        return $period === null ? null : __('season :from–:to', [
            'from' => $period->starts_on->format('d.m'),
            'to' => $period->ends_on->format('d.m'),
        ]);
    }

    /**
     * Przedsprzedaż — tylko gdy okno jest otwarte albo otwiera się w ciągu 30 dni (§3.2).
     * ⚠️ Co widać po zamknięciu okna, a przed otwarciem sprzedaży sezonu, rozstrzyga TODO-5 — wyciąg
     * wtedy o przedsprzedaży milczy, a komórki pokazują to, co zwróci warstwa oferty.
     */
    private function presale(CarbonImmutable $today): ?string
    {
        /** @var SalePeriod|null $period */
        $period = $this->fishery->salePeriods()
            ->whereNotNull('presale_opens_on')
            ->whereNotNull('presale_closes_on')
            ->whereDate('presale_closes_on', '>=', $today->toDateString())
            ->whereDate('presale_opens_on', '<=', $today->addDays(self::PRESALE_ANNOUNCE_DAYS)->toDateString())
            ->orderBy('presale_opens_on')
            ->first();

        if ($period === null) {
            return null;
        }

        return implode(' · ', array_filter([
            __('Presale :year: :from–:to', [
                'year' => $period->starts_on->format('Y'),
                'from' => $period->presale_opens_on->format('d.m'),
                'to' => $period->presale_closes_on->format('d.m.Y'),
            ]),
            $period->presale_min_nights !== null
                ? trans_choice('min. :count night|min. :count nights', (int) $period->presale_min_nights, ['count' => (int) $period->presale_min_nights])
                : null,
            $period->presale_discount_percent !== null && (float) $period->presale_discount_percent > 0
                ? '−'.rtrim(rtrim(number_format((float) $period->presale_discount_percent, 2, ',', ''), '0'), ',').'%'
                : null,
        ]));
    }

    private function time(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }
}
