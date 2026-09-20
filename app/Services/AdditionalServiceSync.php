<?php

namespace App\Services;

/**
 * Zapis powiązań stanowiska z usługami dodatkowymi.
 *
 * Pivot niesie `is_required`, więc `sync()` dostaje tablicę atrybutów, nie samych ID.
 */
final class AdditionalServiceSync
{
    public static function syncAdditionalServices($record, array $services): void
    {
        $record->additionalServices()->sync(
            collect($services)
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
