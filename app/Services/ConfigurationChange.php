<?php

namespace App\Services;

use App\Enums\ConfigurationChangeKind;

/**
 * Opis zmiany konfiguracji: rodzaj + parametry (ADR-027 pkt 1). Obiekt wartości — da się go
 * zapisać w sesji jako tablicę (`toArray()`/`fromArray()`) na czas ekranu decyzji.
 *
 * `positionIds` — stanowiska objęte zmianą; `startsOn`/`endsOn` — okno blokady w dniach strefy łowiska
 * (`endsOn = null` = „do odwołania"); przy wycofaniu stanowiska okna nie ma (wszystkie przyszłe pobyty).
 * `payload` — to, co zapisze się po decyzji (pola blokady, id edytowanej blokady, id stanowiska).
 */
final readonly class ConfigurationChange
{
    /**
     * @param  array<int, int>  $positionIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ConfigurationChangeKind $kind,
        public int $fisheryId,
        public array $positionIds,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'fishery_id' => $this->fisheryId,
            'position_ids' => $this->positionIds,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ConfigurationChangeKind::from((string) $data['kind']),
            (int) $data['fishery_id'],
            array_map('intval', (array) $data['position_ids']),
            $data['starts_on'] ?? null,
            $data['ends_on'] ?? null,
            (array) ($data['payload'] ?? []),
        );
    }
}
