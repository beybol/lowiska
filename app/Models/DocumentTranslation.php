<?php

namespace App\Models;

use App\Services\FisheryDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wariant językowy wersji dokumentu — ta sama treść prawna w innym języku (zadanie 049, ADR-028 §1).
 * Wiążący jest wariant w `documents.locale`; który język wiąże wobec konsumenta — TODO-3.
 *
 * ⚠️ Od dnia wejścia w życie wersji jej warianty są nienaruszalne jak sama wersja — ktoś mógł zaakceptować
 * właśnie ten wariant (rejestr zgód zapisuje język, który wędkarz zobaczył).
 */
class DocumentTranslation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'document_id',
        'locale',
        'title',
        'content',
    ];

    protected static function booted(): void
    {
        $guard = function (self $translation): void {
            $document = Document::withTrashed()->find($translation->document_id);

            if ($document instanceof Document && FisheryDocuments::isLocked($document)) {
                throw ValidationException::withMessages([
                    'translations' => __('A version in force can not be changed — create a new version instead.'),
                ]);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
