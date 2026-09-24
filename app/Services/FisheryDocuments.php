<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Fishery;
use Carbon\CarbonImmutable;

/**
 * JEDYNE miejsce liczące, która wersja dokumentu łowiska obowiązuje i w jakim stanie jest
 * każda wersja (zadanie 021, ADR-017).
 *
 * ⚠️ **Obowiązuje wersja o najpóźniejszej dacie wejścia w życie ≤ dziś w strefie łowiska** —
 * od północy tego dnia. Wersja nie ma daty końca: przestaje obowiązywać, gdy wchodzi następna
 * wersja tego samego rodzaju. Stan nie jest zapisywany jako kolumna.
 *
 * ⚠️ Wędkarza wiąże wersja obowiązująca w chwili ZAKUPU, nie w dniu pobytu — wskazanie wersji
 * zapisze transakcja (G1, Z1).
 */
final class FisheryDocuments
{
    /** @var array<string, Document|null> */
    private array $current = [];

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Wersja obowiązująca dziś — albo `null`, gdy łowisko jej nie ma.
     */
    public function current(DocumentType $type): ?Document
    {
        if (array_key_exists($type->value, $this->current)) {
            return $this->current[$type->value];
        }

        /** @var Document|null $document */
        $document = $this->fishery->documents()
            ->where('type', $type->value)
            ->whereDate('effective_from', '<=', self::today($this->fishery)->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $this->current[$type->value] = $document;
    }

    public function statusOf(Document $document): DocumentStatus
    {
        if (! self::isLocked($document)) {
            return DocumentStatus::Scheduled;
        }

        return $this->current($document->type)?->is($document)
            ? DocumentStatus::Current
            : DocumentStatus::Archived;
    }

    /**
     * Czy wersja weszła już w życie — od tego dnia jest nienaruszalna i nieusuwalna.
     *
     * ⚠️ Rozstrzyga data ZAPISANA w bazie (`getOriginal`), nie wartość przysłana w żądaniu:
     * inaczej formularz z przyszłą datą odblokowałby edycję wersji obowiązującej.
     */
    public static function isLocked(Document $document): bool
    {
        $stored = $document->exists ? $document->getRawOriginal('effective_from') : null;

        if ($stored === null) {
            return false;
        }

        $fishery = $document->fishery;
        $timezone = $fishery instanceof Fishery ? self::timezoneOf($fishery) : 'Europe/Warsaw';
        $effective = CarbonImmutable::parse(substr((string) $stored, 0, 10), $timezone)->startOfDay();

        return $effective <= CarbonImmutable::now($timezone)->startOfDay();
    }

    /** „Dziś" w strefie łowiska — wspólne dla stanu wersji i reguł daty. */
    public static function today(Fishery $fishery): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezoneOf($fishery))->startOfDay();
    }

    /** Domyślna data wejścia w życie nowej i skopiowanej wersji: dziś + 14 dni. */
    public static function defaultEffectiveFrom(Fishery $fishery): CarbonImmutable
    {
        return self::today($fishery)->addDays(14);
    }

    private static function timezoneOf(Fishery $fishery): string
    {
        return $fishery->timezone ?: 'Europe/Warsaw';
    }
}
