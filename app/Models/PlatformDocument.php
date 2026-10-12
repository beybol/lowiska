<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Dokument Fisherya — regulamin albo polityka prywatności portalu (zadanie 049, ADR-028 §1).
 *
 * ⚠️ Ta sama tabela i te same reguły wersji co dokument łowiska (`Document`): jedna wersja obowiązująca na
 * rodzaj, nienaruszalność od dnia wejścia w życie, usuwanie wyłącznie zaplanowanej. Osobna klasa istnieje
 * po to, żeby zasób panelu admina miał własną politykę (`PlatformDocumentPolicy`) — `Document` jej nie ma
 * i autoryzuje się przez łowisko (`autoryzacja.md` §5, §7).
 *
 * Zakres globalny (`market_country_id = null`); aneks rynkowy (rynek = kraj łowiska) przewiduje schemat,
 * nie interfejs.
 */
class PlatformDocument extends Document
{
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('platform', fn (Builder $query) => $query->whereNull('documents.fishery_id'));

        static::creating(function (self $document): void {
            $document->fishery_id = null;
        });
    }

    public function getMorphClass(): string
    {
        return Document::class;
    }
}
