<?php

namespace App\Models;

use App\Services\FisheryReadCache;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Wiersz tabeli łączącej blokady ze stanowiskami. Istnieje WYŁĄCZNIE po to, żeby `attach`/`detach`/`sync`
 * wywoływały zdarzenia — bez modelu tabela łącząca zmienia się po cichu, a magazyn odczytów na żądanie
 * (ADR-029, reguła 4) podawałby blokady sprzed zmiany. Dotyczy to także formularza blokady w Filamencie,
 * który zapisuje stanowiska PO zapisie modelu (zdarzenie `AvailabilityBlock` pada wcześniej).
 *
 * Czyści cały magazyn, nie jedno łowisko: wiersz zna tylko identyfikatory, a ustalenie łowiska kosztowałoby
 * zapytanie przy każdym wierszu. Magazyn żyje jedno żądanie, więc to tanie.
 */
class AvailabilityBlockPosition extends Pivot
{
    protected $table = 'availability_block_position';

    protected static function booted(): void
    {
        $flush = static function (): void {
            if (app()->resolved(FisheryReadCache::class)) {
                FisheryReadCache::instance()->flush();
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
