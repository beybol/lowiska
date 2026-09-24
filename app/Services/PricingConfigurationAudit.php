<?php

namespace App\Services;

use App\Enums\PriceRuleKind;
use App\Models\Fishery;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * Pomocnicze sprawdzenia cennika — **ostrzeżenia i diagnostyka, nigdy błędy** (G3).
 *
 * ⚠️ Twarde reguły zapisu mieszkają w `app/Rules/`; tutaj są wyłącznie te sprawdzenia, które
 * **nie mogą** być błędem, bo łowisko może świadomie chcieć takiej konfiguracji albo dopiero
 * ją porządkuje. Laravelowa reguła walidacji potrafi tylko odrzucić zapis, więc ostrzeżenia
 * potrzebują osobnego domu — usługi (`CLAUDE.md`: reguła do `app/Rules/`, usługa do
 * `app/Services/`).
 *
 * ⚠️ **To nie jest gwarancja, tylko podpowiedź.** Dziura w cenniku powstaje także poza ekranem
 * cennika — wydłużeniem okresu sprzedaży albo skróceniem stawki — a to sprawdzenie widzi
 * wyłącznie **dzisiejszy** stan reguł. Gwarancją jest odmowa przy sprzedaży (`StayOffer`).
 */
final class PricingConfigurationAudit
{
    /**
     * @param  array<int, PriceRule>|null  $rules  cennik wczytany przez wołającego — kalendarz
     *                                             (019) podaje ten sam zbiór co warstwie oferty;
     *                                             `null` = sprawdzenie wczyta go samo
     */
    public function __construct(
        private readonly Fishery $fishery,
        private readonly ?array $rules = null,
    ) {}

    /**
     * Pierwsza znaleziona dziura w cenniku albo `null`.
     *
     * ⚠️ **Zakres sprawdzenia to same DOBY i nic więcej** — i to jest zmiana wobec pierwszej
     * implementacji, nie uproszczenie na skróty. Dopasowanie stawki zależy teraz wyłącznie od
     * daty (obsada i rola zeszły ze stawki), a dopłaty dziur nie tworzą, bo tylko dodają.
     * Pętla po obsadach `1…max_anglers` i po rolach nie mogłaby więc znaleźć nic, czego nie
     * znajdzie sama iteracja po dobach — byłaby wyłącznie kosztem.
     *
     * | Wymiar | Zakres |
     * |---|---|
     * | doby | wszystkie doby okresów sprzedaży **od dziś** do końca ostatniego okresu |
     * | stan cennika | reguły obowiązujące **dziś** |
     */
    public function firstPricingGap(): ?CarbonImmutable
    {
        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            return null;
        }

        $timezone = $this->fishery->timezoneName();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $calendar = new FishingDayCalendar($this->fishery);
        $resolver = new PriceRuleResolver($this->rules());

        foreach ($this->fishery->salePeriods()->orderBy('starts_on')->get() as $period) {
            $from = CarbonImmutable::parse($period->starts_on->toDateString(), $timezone)->startOfDay();
            $to = CarbonImmutable::parse($period->ends_on->toDateString(), $timezone)->endOfDay();

            // Przeszłości nie sprzedajemy.
            if ($from < $today) {
                $from = $today;
            }

            if ($from > $to) {
                continue;
            }

            foreach ($calendar->daysBetween($from, $to) as $night) {
                if ($resolver->candidatesFor($night) === []) {
                    return $night->startsOn;
                }
            }
        }

        return null;
    }

    /**
     * Stawki, które nie wygrywają w ŻADNEJ dobie swojego okresu — czyli zapisane, ale martwe.
     *
     * ⚠️ **Po co to jest.** Po zdjęciu priorytetów stawkę z datą końca da się już tylko obniżyć:
     * „90 zł w lipcu" przy bezterminowych 70 zł przegra w każdej lipcowej dobie i nie zrobi nic.
     * Zapis takiej stawki jest legalny i **nie jest ostrzegany w formularzu** — uwidacznia go
     * kalendarz podglądowy (019), żeby wiedza o nachodzeniu miała jeden dom.
     *
     * ⚠️ **To arytmetyka przedziałów, nie przebieg po kalendarzu.** Zbiór stawek pokrywających
     * datę zmienia się wyłącznie na granicach ich okresów, więc wystarczy sprawdzić po jednym
     * dniu reprezentatywnym na przedział między kolejnymi granicami. Liczy się to RAZ na cennik,
     * niezależnie od liczby stanowisk i długości sezonu.
     *
     * @return array<int, PriceRule>
     */
    public function deadRates(): array
    {
        $rates = array_values(array_filter(
            $this->rules(),
            static fn (PriceRule $rule): bool => $rule->kind === PriceRuleKind::Rate && ! $rule->is_suspended,
        ));

        if (count($rates) < 2) {
            return [];
        }

        $resolver = new PriceRuleResolver($rates);
        $alive = [];

        foreach ($this->representativeDays($rates) as $day) {
            $winner = $resolver->cheapestOn($day);

            if ($winner instanceof PriceRule) {
                $alive[(int) $winner->id] = true;
            }
        }

        return array_values(array_filter(
            $rates,
            static fn (PriceRule $rule): bool => ! isset($alive[(int) $rule->id]),
        ));
    }

    /**
     * Po jednym dniu z każdego przedziału, w którym zbiór pokrywających stawek jest stały.
     *
     * Granicami są początki okresów i dni tuż po ich końcach; dochodzi jeden dzień PRZED
     * najwcześniejszą granicą, bo przedział otwarty od dołu też musi mieć reprezentanta.
     *
     * @param  array<int, PriceRule>  $rates
     * @return array<int, CarbonImmutable>
     */
    private function representativeDays(array $rates): array
    {
        $boundaries = [];
        // ⚠️ Strefa ŁOWISKA, jak w reszcie pakietu. `coversDay()` porównuje dziś łańcuchy `Y-m-d`,
        // więc strefa aplikacji niczego nie psuła — ale zaczęłaby kłamać przy pierwszym
        // porównaniu momentów zamiast dat (zadanie 023, poz. 7).
        $timezone = $this->fishery->timezoneName();

        // ⚠️ Rzut `date` oddaje `Illuminate\Support\Carbon` (mutowalny), a nie `CarbonImmutable`
        // — bez jawnej zamiany arytmetyka na datach modyfikowałaby atrybut modelu w miejscu.
        foreach ($rates as $rule) {
            if ($rule->first_day_on !== null) {
                $from = CarbonImmutable::parse($rule->first_day_on->toDateString(), $timezone)->startOfDay();
                $boundaries[$from->toDateString()] = $from;
            }

            if ($rule->last_day_on !== null) {
                $after = CarbonImmutable::parse($rule->last_day_on->toDateString(), $timezone)->addDay()->startOfDay();
                $boundaries[$after->toDateString()] = $after;
            }
        }

        if ($boundaries === []) {
            // Same stawki bezterminowe bez dat — jeden przedział na wszystko.
            return [CarbonImmutable::now($timezone)->startOfDay()];
        }

        ksort($boundaries);
        $days = array_values($boundaries);
        $earliest = $days[0]->subDay();

        array_unshift($days, $earliest);

        return $days;
    }

    /**
     * Największa obsada do wyboru w formularzu; stanowisko bez podanej pojemności liczy się jako 1.
     *
     * ⚠️ Publiczna, bo tej liczby potrzebuje lista opcji „tylko przy obsadzie" na dopłacie.
     * ⚠️ **Nie jest już wymiarem sprawdzania dziury w cenniku** — stawka obsady nie zna.
     */
    public function largestAnglerCapacity(): int
    {
        $largest = 1;

        foreach ($this->fishery->positions()->get() as $position) {
            $largest = max($largest, (int) ($position->max_anglers ?? 1));
        }

        return $largest;
    }

    /**
     * @return array<int, PriceRule>
     */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        /** @var array<int, PriceRule> $rules */
        $rules = $this->fishery->priceRules()->get()->all();

        return $rules;
    }
}
