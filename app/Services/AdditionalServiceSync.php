<?php

namespace App\Services;

use App\Models\AdditionalService;
use App\Rules\RecordsBelongToFishery;

/**
 * Zapis powiązań stanowiska z usługami dodatkowymi.
 *
 * Pivot niesie `is_required`, więc `sync()` dostaje tablicę atrybutów, nie samych ID.
 *
 * ⚠️ To JEDYNE wejście zapisu tej relacji i dlatego tutaj stoi bramka przynależności.
 * Identyfikatory usług przychodzą z repeatera, czyli od klienta; zawężenie listy opcji
 * w formularzu ich nie sprawdza. Bez tego filtra dało się podpiąć usługę cudzego
 * łowiska pod własne stanowisko (security-review, 2026-09-20).
 *
 * ⚠️ Odrzucone pozycje są POMIJANE, nie zgłaszane błędem: repeater nie ma pojedynczego
 * pola, do którego dałoby się przypiąć komunikat, a operator, który nie majstrował przy
 * żądaniu, nigdy takiej pozycji nie wyśle.
 */
final class AdditionalServiceSync
{
    public static function syncAdditionalServices($record, array $services): void
    {
        $ids = RecordsBelongToFishery::normalize(
            collect($services)->pluck('additional_service_id')->all()
        );

        $allowed = $ids === []
            ? []
            : AdditionalService::query()
                ->whereKey($ids)
                ->where('fishery_id', $record->fishery_id)
                ->pluck('id')
                ->all();

        $record->additionalServices()->sync(
            collect($services)
                ->filter(fn ($item): bool => in_array((int) ($item['additional_service_id'] ?? 0), $allowed, true))
                ->mapWithKeys(function ($item) {
                    return [
                        $item['additional_service_id'] => [
                            'is_required' => $item['is_required'] ?? false,
                        ],
                    ];
                })
                ->toArray()
        );
    }

    public static function extractAdditionalServices(array &$data): array
    {
        $services = $data['additionalServices'] ?? [];
        unset($data['additionalServices']);

        return $services;
    }
}
