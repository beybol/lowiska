<?php

namespace App\Services;

use App\Enums\BlockEffect;
use App\Enums\ConfigurationChangeKind;
use App\Filament\Resources\FisheryResource;
use App\Models\Position;

/**
 * Wejście ścieżek zapisu konfiguracji do mechanizmu kolizji (zadanie 044): czy zmiana dotyka rezerwacji,
 * a jeśli tak — szkic do sesji i adres ekranu decyzji. Strony zapisu (blokady, stanowiska) pytają tutaj,
 * zanim cokolwiek zapiszą; zasób Filamenta nie zna tej reguły (ADR-027 pkt 1).
 */
final class CollisionGate
{
    public function __construct(private readonly ConfigurationCollisionService $collisions = new ConfigurationCollisionService) {}

    /**
     * Blokada albo zawieszenie cechy przed zapisem.
     *
     * @param  array<string, mixed>  $data  pola blokady po bramce łowiska
     * @param  array<int, int|string>  $positionIds  zbiór stanowisk z formularza (po walidacji)
     * @return array{redirect: string|null, informational: int}
     *                                                          `redirect` — ekran decyzji (blokada sprzedaży);
     *                                                          `informational` — liczba rezerwacji objętych
     *                                                          zawieszeniem cechy (bez decyzji, ADR-027 pkt 8)
     */
    public function forBlock(array $data, array $positionIds, ?int $blockId = null): array
    {
        $kind = ($data['effect'] ?? null) === BlockEffect::AttributeSuspended->value
            ? ConfigurationChangeKind::AttributeSuspension
            : ConfigurationChangeKind::SaleBlock;

        $change = new ConfigurationChange(
            kind: $kind,
            fisheryId: (int) $data['fishery_id'],
            positionIds: array_values(array_map('intval', $positionIds)),
            startsOn: self::date($data['starts_on'] ?? null),
            endsOn: self::date($data['ends_on'] ?? null),
            payload: array_filter(['fields' => $data, 'block_id' => $blockId], fn ($value): bool => $value !== null),
        );

        $affected = $this->collisions->affected($change)->count();

        if ($affected === 0) {
            return ['redirect' => null, 'informational' => 0];
        }

        if (! $kind->requiresDecision()) {
            return ['redirect' => null, 'informational' => $affected];
        }

        return ['redirect' => self::decisionUrl($change), 'informational' => 0];
    }

    /**
     * Wycofanie stanowiska ze sprzedaży — adres ekranu decyzji albo `null`, gdy nie ma czego rozstrzygać.
     */
    public function forWithdrawal(Position $position): ?string
    {
        $change = new ConfigurationChange(
            kind: ConfigurationChangeKind::PositionWithdrawal,
            fisheryId: (int) $position->fishery_id,
            positionIds: [(int) $position->getKey()],
            payload: ['position_id' => (int) $position->getKey()],
        );

        return $this->collisions->affected($change)->isEmpty() ? null : self::decisionUrl($change);
    }

    private static function decisionUrl(ConfigurationChange $change): string
    {
        return FisheryResource::getUrl('collisions', [
            'record' => $change->fisheryId,
            'token' => CollisionDrafts::store($change),
        ]);
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
