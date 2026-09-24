<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Services\FisheryDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wersja dokumentu łowiska — regulaminu albo polityki prywatności (zadanie 021, ADR-017).
 *
 * ⚠️ **Od dnia wejścia w życie wersja jest nienaruszalna** (F10): tytuł, treść, data i rodzaj.
 * Pilnują tego haki modelu, nie formularz — rozstrzygają po dacie ZAPISANEJ w bazie, więc
 * żądanie z przyszłą datą w miejscu obowiązującej też jest odrzucane. **Flagi wymagalności
 * są edytowalne zawsze** — to sposób użycia dokumentu, nie jego treść.
 *
 * ⚠️ Usunąć (miękko) wolno wyłącznie wersję ZAPLANOWANĄ — obowiązującej i archiwalnej nie,
 * bo ktoś mógł ją zaakceptować.
 *
 * ⚠️ Model nie ma własnego zasobu Filamenta, więc nie ma własnej polityki — autoryzuje się przez
 * łowisko (`FisheryPolicy`, `autoryzacja.md` §5).
 */
class Document extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /** Pola zamrożone od dnia wejścia w życie. */
    public const LOCKED_FIELDS = ['type', 'title', 'content', 'effective_from'];

    protected $fillable = [
        'fishery_id',
        'type',
        'title',
        'effective_from',
        'content',
        'required_at_purchase',
        'required_at_registration',
    ];

    protected $casts = [
        'type' => DocumentType::class,
        'effective_from' => 'date',
        'required_at_purchase' => 'boolean',
        'required_at_registration' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty(self::LOCKED_FIELDS) && FisheryDocuments::isLocked($document)) {
                throw ValidationException::withMessages([
                    'content' => __('A version in force can not be changed — create a new version instead.'),
                ]);
            }
        });

        static::deleting(function (self $document): void {
            if (FisheryDocuments::isLocked($document)) {
                throw ValidationException::withMessages([
                    'effective_from' => __('Only a scheduled version can be deleted.'),
                ]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    /**
     * @return BelongsTo<Fishery, $this>
     */
    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }
}
