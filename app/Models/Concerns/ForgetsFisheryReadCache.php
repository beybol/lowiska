<?php

namespace App\Models\Concerns;

use App\Services\FisheryReadCache;

/**
 * Zapis albo usunięcie wpisu konfiguracji łowiska czyści jego odczyty z magazynu na żądanie (ADR-029,
 * reguła 4). Dzięki temu render po zapisie w tym samym żądaniu (np. blokada z kalendarza) widzi stan po zapisie.
 *
 * Tabelę łączącą blokady ze stanowiskami obsługuje osobno model `AvailabilityBlockPosition`.
 * ⚠️ Zdarzeń NIE wywołują masowe `query()->update()`/`delete()` ani `DB::table()` — tam
 * `FisheryReadCache::forget()` woła się jawnie.
 */
trait ForgetsFisheryReadCache
{
    protected static function bootForgetsFisheryReadCache(): void
    {
        $forget = static function (self $model): void {
            // Bez rozwiązywania z kontenera, gdy nikt w tym żądaniu nie czytał — magazyn powstaje leniwie.
            if (app()->resolved(FisheryReadCache::class)) {
                FisheryReadCache::instance()->forget((int) $model->getAttribute('fishery_id') ?: null);
                FisheryReadCache::instance()->forget((int) $model->getOriginal('fishery_id') ?: null);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
