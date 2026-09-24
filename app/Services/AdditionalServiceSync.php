<?php

namespace App\Services;

use App\Enums\PositionAttributeType;
use App\Models\AdditionalService;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Rules\RecordsBelongToFishery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
 * ⚠️ **Przypiąć da się wyłącznie usługę o zasięgu „wybrane stanowiska"** (zadanie 020). Usługa
 * ogólnołowiskowa obejmuje każde stanowisko bez przypięcia, więc przypięcie niczego by nie
 * znaczyło poza `is_required`, którego taka usługa mieć nie może. Lista opcji w formularzu tego
 * nie gwarantuje — stąd warunek tutaj, w każdym wejściu zapisu.
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
                ->pinnable()
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

    /**
     * Przypięcie usługi WPROST do każdego z podanych stanowisk — akcja „Przypnij usługę".
     *
     * ⚠️ Dopisanie, nie `sync()` całej listy: inne przypięcia stanowiska zostają nietknięte,
     * a przypięcie już istniejące dostaje nową wartość `is_required`.
     *
     * ⚠️ Bramka jak w `syncAdditionalServices()`: identyfikatory usługi i stanowisk pochodzą od
     * klienta. Usługa musi dać się przypiąć, operator musi móc ją edytować, a stanowisko musi
     * należeć do łowiska usługi i dać się edytować — pozostałe są pomijane.
     *
     * @param  iterable<int, Position>  $positions
     * @return array<int, Position> stanowiska, na których zapisano przypięcie
     */
    public static function pin(mixed $serviceId, iterable $positions, bool $isRequired): array
    {
        $service = self::pinnableService($serviceId);

        if ($service === null) {
            return [];
        }

        $pinned = [];

        foreach (self::positionsOf($service, $positions) as $position) {
            $position->additionalServices()->syncWithoutDetaching([
                $service->getKey() => ['is_required' => $isRequired],
            ]);
            $pinned[] = $position;
        }

        return $pinned;
    }

    /**
     * Odpięcie usługi od podanych stanowisk — akcja „Odepnij usługę". Stanowisko bez tej usługi
     * jest pomijane bez błędu, inne przypięcia zostają.
     *
     * @param  iterable<int, Position>  $positions
     * @return int liczba usuniętych przypięć
     */
    public static function unpin(mixed $serviceId, iterable $positions): int
    {
        $service = self::pinnableService($serviceId);

        if ($service === null) {
            return 0;
        }

        $removed = 0;

        foreach (self::positionsOf($service, $positions) as $position) {
            $removed += $position->additionalServices()->detach($service->getKey());
        }

        return $removed;
    }

    /**
     * Zapis wymaganych cech usługi — bramka: cecha istnieje w słowniku i jest FLAGĄ.
     *
     * ⚠️ Wymóg cechy usuniętej miękko ze słownika ZOSTAJE w tabeli pośredniej, choć formularz go
     * nie pokazuje — inaczej pierwszy zapis usługi po usunięciu cechy skasowałby wymóg, a
     * `restore()` cechy nie miałby czego przywrócić.
     *
     * ⚠️ Zmiana trafia do dziennika zmian usługi: relacja wiele-do-wielu nie przechodzi przez
     * `LogsActivity` sama.
     *
     * @param  array<int, mixed>  $attributeIds
     */
    public static function syncRequiredAttributes(AdditionalService $service, array $attributeIds): void
    {
        $ids = RecordsBelongToFishery::normalize($attributeIds);

        $flags = $ids === []
            ? []
            : PositionAttribute::query()
                ->whereKey($ids)
                ->where('type', PositionAttributeType::Flag->value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

        $before = self::requiredAttributeIds($service);

        $trashed = PositionAttribute::onlyTrashed()
            ->whereIn('id', $before)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $after = array_values(array_unique([...$flags, ...$trashed]));
        sort($after);

        if ($after === $before) {
            return;
        }

        $service->requiredAttributes()->sync($after);

        activity()
            ->performedOn($service)
            ->event('updated')
            ->withChanges([
                'old' => ['required_attribute_ids' => $before],
                'attributes' => ['required_attribute_ids' => $after],
            ])
            ->log('updated');
    }

    /**
     * Identyfikatory wymaganych cech zapisane w tabeli pośredniej — także cech usuniętych miękko.
     *
     * @return array<int, int>
     */
    public static function requiredAttributeIds(AdditionalService $service): array
    {
        $ids = DB::table('additional_service_required_attribute')
            ->where('additional_service_id', $service->getKey())
            ->pluck('position_attribute_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        sort($ids);

        return $ids;
    }

    private static function pinnableService(mixed $serviceId): ?AdditionalService
    {
        if (! is_numeric($serviceId)) {
            return null;
        }

        $service = AdditionalService::query()->whereKey((int) $serviceId)->pinnable()->first();

        return $service instanceof AdditionalService && Gate::allows('update', $service) ? $service : null;
    }

    /**
     * @param  iterable<int, Position>  $positions
     * @return array<int, Position>
     */
    private static function positionsOf(AdditionalService $service, iterable $positions): array
    {
        $allowed = [];

        foreach ($positions as $position) {
            if ((int) $position->fishery_id === (int) $service->fishery_id
                && Gate::allows('update', $position)) {
                $allowed[] = $position;
            }
        }

        return $allowed;
    }
}
