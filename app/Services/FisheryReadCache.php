<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\SalePeriod;
use App\Models\WholeTermPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Odczyty konfiguracji łowiska, z których korzystają usługi dostępności — RAZ na żądanie i łowisko
 * (ADR-029). Siatki tworzą osobne instancje usług na stanowisko i na pytanie, więc pamięć w instancji
 * nie wystarczała: jedno kliknięcie w kalendarzu powtarzało te same zapytania kilkadziesiąt razy.
 *
 * Reguły (ADR-029, `dostepnosc.md` §2):
 * - rejestracja `scoped` (AppServiceProvider) — instancja ginie z żądaniem i z zadaniem kolejki;
 *   nigdy `singleton`, nigdy pole komponentu Livewire ani pole statyczne;
 * - LENIWIE: konstruktor niczego nie wczytuje, każda kolekcja osobno, przy pierwszym pytaniu,
 *   wyłącznie dla pytanego łowiska; nic nie rozgrzewa magazynu z góry;
 * - usługi dostępności czytają te dane WYŁĄCZNIE tędy;
 * - zajętość (sloty rezerwacji) tu NIE trafia — ochrona przed podwójną rezerwacją nie zależy od pamięci;
 * - unieważnianie: zdarzenia modeli (`SalePeriod`, `WholeTermPeriod`, `AvailabilityBlock`) i jawne
 *   `forget()` przy zmianach tabeli łączącej blokady ze stanowiskami; zapis rezerwacji czyści wpisy
 *   łowiska po blokadzie wiersza (`ReservationBooking`).
 *
 * ⚠️ Zapamiętywanie MIĘDZY żądaniami wymaga osobnej decyzji z uzasadnieniem pomiarowym — ta klasa jest
 * jej docelowym miejscem, nie zgodą na nią.
 */
final class FisheryReadCache
{
    /** @var array<int, array<int, SalePeriod>> */
    private array $salePeriods = [];

    /** @var array<int, array<int, WholeTermPeriod>> */
    private array $wholeTermPeriods = [];

    /**
     * Blokady łowiska po stanowisku i skutku: `[position_id][effect] => list<AvailabilityBlock>`.
     *
     * @var array<int, array<int, array<string, list<AvailabilityBlock>>>>
     */
    private array $blocks = [];

    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * Okresy sprzedaży łowiska, rosnąco po początku.
     *
     * @return array<int, SalePeriod>
     */
    public function salePeriods(Fishery $fishery): array
    {
        return $this->salePeriods[(int) $fishery->getKey()] ??= $fishery->salePeriods()->orderBy('starts_on')->get()->all();
    }

    /**
     * Okresy „cały termin" łowiska, rosnąco po pierwszej dobie.
     *
     * @return array<int, WholeTermPeriod>
     */
    public function wholeTermPeriods(Fishery $fishery): array
    {
        return $this->wholeTermPeriods[(int) $fishery->getKey()] ??= $fishery->wholeTermPeriods()->orderBy('first_day_on')->get()->all();
    }

    /**
     * Wpisy o dostępności stanowiska o danym skutku — z jednego zapytania o całe łowisko (bez N+1).
     *
     * @return Collection<int, AvailabilityBlock>
     */
    public function blocksFor(Position $position, BlockEffect $effect): Collection
    {
        $fisheryId = (int) $position->fishery_id;

        return new Collection($this->blocksOf($fisheryId)[(int) $position->getKey()][$effect->value] ?? []);
    }

    /**
     * Wpisy łowiska o danym skutku, pogrupowane po stanowisku (siatki kalendarzy).
     *
     * @return array<int, list<AvailabilityBlock>>
     */
    public function blocksByPosition(Fishery $fishery, BlockEffect $effect): array
    {
        $byPosition = [];

        foreach ($this->blocksOf((int) $fishery->getKey()) as $positionId => $byEffect) {
            if (isset($byEffect[$effect->value])) {
                $byPosition[$positionId] = $byEffect[$effect->value];
            }
        }

        return $byPosition;
    }

    /** Zapomina wszystko o łowisku — po zapisie, który zmienia jego okresy albo blokady. */
    public function forget(Fishery|int|null $fishery): void
    {
        if ($fishery === null) {
            return;
        }

        $id = $fishery instanceof Fishery ? (int) $fishery->getKey() : $fishery;

        unset($this->salePeriods[$id], $this->wholeTermPeriods[$id], $this->blocks[$id]);
    }

    public function flush(): void
    {
        $this->salePeriods = $this->wholeTermPeriods = $this->blocks = [];
    }

    /**
     * @return array<int, array<string, list<AvailabilityBlock>>>
     */
    private function blocksOf(int $fisheryId): array
    {
        if (! isset($this->blocks[$fisheryId])) {
            $grouped = [];

            // Ten sam kształt co dawne zapytanie per stanowisko: wpisy nieusunięte, przez tabelę łączącą, z cechą.
            $blocks = AvailabilityBlock::query()
                ->where('fishery_id', $fisheryId)
                ->with(['positions:id', 'attribute'])
                ->orderBy('id')
                ->get();

            foreach ($blocks as $block) {
                foreach ($block->positions as $position) {
                    $grouped[(int) $position->getKey()][$block->effect->value][] = $block;
                }
            }

            $this->blocks[$fisheryId] = $grouped;
        }

        return $this->blocks[$fisheryId];
    }
}
