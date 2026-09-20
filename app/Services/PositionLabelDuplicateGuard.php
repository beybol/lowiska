<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wykrywa duplikaty etykiet stanowisk w obrębie łowiska, zanim migracja spróbuje
 * nałożyć indeks unikalny `(fishery_id, name)`.
 *
 * ⚠️ Klasa istnieje, bo logiki zaszytej w ciele migracji NIE DA SIĘ przetestować:
 * `RefreshDatabase` uruchamia migracje w `setUp()`, więc test zawsze startuje po tym,
 * jak migracja już przeszła (`docs/conventions/dziennik-zmian.md` §2). Bez tego
 * komunikat o duplikatach byłby kodem, który pierwszy raz wykona się na produkcji.
 *
 * ⚠️ Sprawdzenie celowo obejmuje wiersze usunięte miękko — indeks też je obejmuje,
 * bo etykieta wycofanego stanowiska nie ma wracać do obiegu. Pomija natomiast wiersze
 * bez łowiska: MySQL traktuje `NULL` w indeksie unikalnym jako wartości różne, więc
 * one indeksu nie naruszą.
 */
final class PositionLabelDuplicateGuard
{
    /**
     * @return Collection<int, object{fishery_id: int, name: string, total: int}>
     */
    public function find(): Collection
    {
        /** @var Collection<int, object{fishery_id: int, name: string, total: int}> $duplicates */
        $duplicates = DB::table('positions')
            ->select('fishery_id', 'name', DB::raw('COUNT(*) as total'))
            ->whereNotNull('fishery_id')
            ->groupBy('fishery_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $duplicates;
    }

    /**
     * @throws RuntimeException gdy duplikaty istnieją — z listą winnych rekordów,
     *                          bo błąd bazy („Duplicate entry … for key") nie mówi,
     *                          KTÓRE stanowiska trzeba poprawić
     */
    public function assertNone(): void
    {
        $duplicates = $this->find();

        if ($duplicates->isEmpty()) {
            return;
        }

        throw new RuntimeException($this->message($duplicates));
    }

    /**
     * @param  Collection<int, object{fishery_id: int, name: string, total: int}>  $duplicates
     */
    public function message(Collection $duplicates): string
    {
        $details = $duplicates
            ->map(fn (object $row): string => sprintf(
                '  - łowisko #%d, etykieta "%s" — %d razy',
                $row->fishery_id,
                $row->name,
                $row->total,
            ))
            ->implode(PHP_EOL);

        return 'Nie można nałożyć indeksu unikalnego (fishery_id, name) na tabelę positions — '
            .'w obrębie łowiska istnieją duplikaty etykiet stanowisk:'.PHP_EOL.$details.PHP_EOL
            .'Ujednolić etykiety (także na stanowiskach usuniętych miękko) i powtórzyć migrację.';
    }
}
