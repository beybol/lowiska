<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Models\Fishery;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;

/**
 * Pomocnicze sprawdzenia cennika po zapisie — **ostrzeżenia, nie błędy** (G3).
 *
 * ⚠️ Twarde reguły zapisu mieszkają w `app/Rules/`; tutaj są wyłącznie te dwa sprawdzenia,
 * które **nie mogą** być błędem, bo łowisko może świadomie chcieć takiej konfiguracji albo
 * dopiero ją porządkuje. Laravelowa reguła walidacji potrafi tylko odrzucić zapis, więc
 * ostrzeżenia potrzebują osobnego domu — usługi (`CLAUDE.md`: reguła do `app/Rules/`,
 * usługa do `app/Services/`).
 *
 * ⚠️ **To nie jest gwarancja, tylko podpowiedź.** Dziura w cenniku powstaje także poza ekranem
 * cennika — wydłużeniem okresu sprzedaży, podniesieniem `max_anglers` albo wygaśnięciem reguły
 * w połowie sezonu — a to sprawdzenie widzi wyłącznie **dzisiejszy** stan reguł. Gwarancją jest
 * odmowa przy sprzedaży (`StayOffer`).
 */
final class PricingConfigurationAudit
{
    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Pierwsza znaleziona dziura w cenniku albo `null`.
     *
     * Zakres sprawdzenia jest zadeklarowany wprost, żeby implementacja nie wybierała go
     * na ślepo (zadanie 018, rozstrzygnięcie 14):
     *
     * | Wymiar | Zakres |
     * |---|---|
     * | doby | wszystkie doby okresów sprzedaży **od dziś** do końca ostatniego okresu |
     * | liczba łowiących | od 1 do największego `max_anglers` wśród stanowisk |
     * | rola | `angler` zawsze; `companion` tylko gdy łowisko dopuszcza osoby towarzyszące |
     * | stan cennika | reguły obowiązujące **dziś** |
     *
     * ⚠️ **Stanowisko bez podanej pojemności liczy się jako 1 łowiący i zero towarzyszących**,
     * a nie jest pomijane: `max_anglers` i `max_people` są `nullable` od zadania 014, więc
     * pominięcie zostawiłoby łowisko z nieuzupełnionymi pojemnościami bez sprawdzenia w ogóle.
     *
     * @return array{night: CarbonImmutable, role: ParticipantRole, anglers: int}|null
     */
    public function firstPricingGap(): ?array
    {
        if (blank($this->fishery->day_start_time) || blank($this->fishery->day_end_time)) {
            return null;
        }

        $timezone = $this->fishery->timezone ?: 'Europe/Warsaw';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $calendar = new FishingDayCalendar($this->fishery);

        /** @var array<int, PriceRule> $rules */
        $rules = $this->fishery->priceRules()->get()->all();
        $resolver = new PriceRuleResolver($rules, $today);

        $roles = [ParticipantRole::Angler];

        if ($this->allowsCompanions()) {
            $roles[] = ParticipantRole::Companion;
        }

        $maxAnglers = $this->largestAnglerCapacity();

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
                foreach ($roles as $role) {
                    for ($anglers = 1; $anglers <= $maxAnglers; $anglers++) {
                        if ($resolver->resolve($night, $role, $anglers)->isResolved()) {
                            continue;
                        }

                        return [
                            'night' => $night->startsOn,
                            'role' => $role,
                            'anglers' => $anglers,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Stawki bez warunku roli, które mają priorytet WYŻSZY niż stawka osoby towarzyszącej
     * i których warunki da się spełnić z nią jednocześnie.
     *
     * ⚠️ To jest druga połowa zabezpieczenia, bez której zasada „stawka towarzyszącej ma
     * najwyższy priorytet" chroni tylko w jedną stronę. Walidacja remisu łapie priorytet
     * **równy**, bo dopiero wtedy powstaje remis — priorytet **wyższy** przepuszcza bez słowa.
     * Operator, który za rok doda „Sylwester 150 zł" bez warunku roli i z priorytetem 200,
     * sprawi, że osoba towarzysząca zapłaci w sylwestra 150 zł: bez błędu, bez ostrzeżenia
     * i bez śladu w konfiguracji. Instrukcja tego nie zagwarantuje, bo zasada obowiązuje
     * tylko dopóty, dopóki ktoś o niej pamięta.
     *
     * ⚠️ **Ostrzeżenie, nie błąd**: łowisko może świadomie chcieć, żeby w sylwestra płacili wszyscy.
     *
     * @return array<int, PriceRule>
     */
    public function ratesOutrankingCompanionRate(): array
    {
        /** @var array<int, PriceRule> $rates */
        $rates = $this->fishery->priceRules()
            ->where('kind', PriceRuleKind::Rate->value)
            ->get()
            ->all();

        $companionRates = array_values(array_filter(
            $rates,
            static fn (PriceRule $rule): bool => $rule->participant_role === ParticipantRole::Companion,
        ));

        if ($companionRates === []) {
            return [];
        }

        $overlap = new PriceRuleOverlap;
        $offenders = [];

        foreach ($rates as $rule) {
            if ($rule->participant_role !== null) {
                continue;
            }

            foreach ($companionRates as $companionRate) {
                if ((int) $rule->priority <= (int) $companionRate->priority) {
                    continue;
                }

                if ($overlap->canMatchSimultaneously($rule, $companionRate)) {
                    $offenders[] = $rule;

                    break;
                }
            }
        }

        return $offenders;
    }

    /**
     * Największa obsada do sprawdzenia; stanowisko bez podanej pojemności liczy się jako 1.
     *
     * Publiczna, bo tej samej liczby potrzebuje lista opcji „liczba łowiących" w formularzu
     * cennika — druga jej implementacja rozjechałaby zakres sprawdzenia z zakresem wyboru.
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
     * Czy łowisko jawnie dopuszcza osoby towarzyszące — czyli czy którekolwiek stanowisko ma
     * `max_people` większe niż `max_anglers`, przy obu wartościach podanych.
     */
    private function allowsCompanions(): bool
    {
        foreach ($this->fishery->positions()->get() as $position) {
            if ($position->max_people !== null
                && $position->max_anglers !== null
                && (int) $position->max_people > (int) $position->max_anglers) {
                return true;
            }
        }

        return false;
    }
}
