<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Fishery;
use App\Models\PlatformDocument;
use Carbon\CarbonImmutable;

/**
 * Która wersja dokumentu Fisherya obowiązuje (zadanie 049, ADR-028) — ta sama reguła co
 * `FisheryDocuments`: najpóźniejsza data wejścia w życie ≤ dziś, od północy. „Dziś" w strefie domyślnej
 * portalu (`Fishery::DEFAULT_TIMEZONE`), tej samej, której `FisheryDocuments::isLocked()` używa dla
 * wersji bez łowiska.
 *
 * ⚠️ Wyłącznie zakres globalny (`market_country_id = null`). Aneks rynkowy (rynek = kraj łowiska,
 * akceptowany przy pierwszym kontakcie z łowiskiem tego rynku) to kolejne zadanie.
 */
final class PlatformDocuments
{
    /** @var array<string, PlatformDocument|null> */
    private array $current = [];

    public function current(DocumentType $type): ?PlatformDocument
    {
        if (array_key_exists($type->value, $this->current)) {
            return $this->current[$type->value];
        }

        /** @var PlatformDocument|null $document */
        $document = PlatformDocument::query()
            ->whereNull('market_country_id')
            ->where('type', $type->value)
            ->whereDate('effective_from', '<=', self::today()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $this->current[$type->value] = $document;
    }

    public function statusOf(PlatformDocument $document): DocumentStatus
    {
        if (! FisheryDocuments::isLocked($document)) {
            return DocumentStatus::Scheduled;
        }

        return $this->current($document->type)?->is($document)
            ? DocumentStatus::Current
            : DocumentStatus::Archived;
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(Fishery::DEFAULT_TIMEZONE)->startOfDay();
    }

    /** Domyślna data wejścia w życie nowej wersji: dziś + 14 dni — jak u łowiska. */
    public static function defaultEffectiveFrom(): CarbonImmutable
    {
        return self::today()->addDays(14);
    }
}
