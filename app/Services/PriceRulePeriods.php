<?php

namespace App\Services;

use App\Enums\PriceRuleKind;
use App\Models\Fishery;
use App\Models\PriceRule;
use Illuminate\Support\Facades\DB;

/**
 * Automatyczne domykanie okresów stawki — jedyne miejsce, w którym cennik zmienia się SAM.
 *
 * ⚠️ **Po co to w ogóle jest, skoro nachodzenie rozstrzyga się na korzyść wędkarza.** Bez
 * domykania każda podwyżka cicho przestałaby działać: stara stawka 70 zł bezterminowo i nowa
 * 80 zł od 2027 nachodzą na siebie, więc wygrałaby tańsza i nowa cena nigdy by nie weszła.
 * Oba mechanizmy mają rozłączne zadania — domykanie pilnuje OSI CZASU, wybór na korzyść
 * wędkarza całej reszty.
 *
 * ⚠️ **Domyka wyłącznie stawka, która SAMA jest bezterminowa** — i ten warunek nie jest
 * ostrożnością, tylko poprawką błędu z pierwszej redakcji zadania 018. Bez niego dodanie
 * promocji „90 zł na 01.07–31.08" ucięłoby bezterminowe 70 zł na 30.06 i od 01.09 nie zostałaby
 * ŻADNA stawka — operator dostałby dziurę w cenniku na resztę sezonu za to, że dodał okno.
 *
 * Rozróżnienie w jednym zdaniu:
 * - stawka bez daty końca = **nowy cennik**, domyka poprzedni;
 * - stawka z datą końca = **okno nakładkowe**, nie domyka niczego.
 *
 * ⚠️ **Tylko przy UTWORZENIU, nigdy przy edycji.** Domknięcie jest nieodwracalne: domknięta
 * stawka ma już `last_day_on`, więc cofnięcie daty na nowej stawce nie przywraca poprzedniej
 * i zostawia dziurę. Przy edycji istniejącego wpisu operator rusza rekord, który już żyje —
 * automat mutujący wtedy sąsiadów jest bardziej zaskakujący niż pomocny.
 */
final class PriceRulePeriods
{
    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Domyka stawki wyparte przez nowo utworzone i zwraca opis tego, co zmieniła.
     *
     * ⚠️ Nowe stawki przetwarzamy w kolejności **rosnącego `first_day_on`**. Ekran jest jednym
     * repeaterem zapisywanym w całości, więc gdy jeden zapis wnosi kilka nowych cenników,
     * bez ustalonej kolejności wynik zależałby od kolejności wierszy w formularzu.
     *
     * ⚠️ Całość idzie w **jednej transakcji**: albo domykamy wszystko, albo nic. Wpisy trafiają
     * do dziennika zmian jak każda inna modyfikacja rekordu — modyfikujemy przecież wpis,
     * którego operator w tym formularzu nie dotknął.
     *
     * @param  array<int, int>  $createdRuleIds  identyfikatory reguł powstałych w tym zapisie
     * @return array<int, array{label: string, until: string}>
     */
    public function closeSupersededRates(array $createdRuleIds): array
    {
        if ($createdRuleIds === []) {
            return [];
        }

        /** @var array<int, PriceRule> $newOpenEnded */
        $newOpenEnded = $this->fishery->priceRules()
            ->where('kind', PriceRuleKind::Rate->value)
            ->whereIn('id', $createdRuleIds)
            ->whereNull('last_day_on')
            ->whereNotNull('first_day_on')
            ->orderBy('first_day_on')
            ->get()
            ->all();

        if ($newOpenEnded === []) {
            return [];
        }

        $closed = [];

        DB::transaction(function () use ($newOpenEnded, &$closed): void {
            foreach ($newOpenEnded as $rule) {
                $closed = array_merge($closed, $this->closeThoseSupersededBy($rule));
            }
        });

        return $closed;
    }

    /**
     * @return array<int, array{label: string, until: string}>
     */
    private function closeThoseSupersededBy(PriceRule $newer): array
    {
        $day = $newer->first_day_on;

        if ($day === null) {
            return [];
        }

        $until = $day->copy()->subDay();

        /** @var array<int, PriceRule> $superseded */
        $superseded = $this->fishery->priceRules()
            ->where('kind', PriceRuleKind::Rate->value)
            ->whereKeyNot($newer->getKey())
            ->whereNull('last_day_on')
            // ⚠️ „Zaczyna się nie później niż nowa" — stawka bezterminowa zaczynająca się
            // PÓŹNIEJ jest cennikiem jeszcze nowszym i to ona domknie tę, nie odwrotnie.
            ->where(function ($query) use ($day): void {
                $query->whereNull('first_day_on')
                    ->orWhere('first_day_on', '<=', $day->toDateString());
            })
            ->get()
            ->all();

        $closed = [];

        foreach ($superseded as $rule) {
            $rule->last_day_on = $until;
            $rule->save();

            $closed[] = [
                'label' => filled($rule->label) ? (string) $rule->label : (string) $rule->amount,
                'until' => $until->toDateString(),
            ];
        }

        return $closed;
    }
}
