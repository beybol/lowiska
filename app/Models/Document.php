<?php

namespace App\Models;

use App\Enums\DocumentChangeKind;
use App\Enums\DocumentType;
use App\Services\FisheryDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wersja dokumentu łowiska — regulaminu albo polityki prywatności (zadanie 021, ADR-017). Wiersz bez
 * łowiska (`fishery_id = null`) to dokument Fisherya — model `PlatformDocument` (zadanie 049, ADR-028).
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

    protected $table = 'documents';

    /**
     * Pola zamrożone od dnia wejścia w życie. Rodzaj zmiany i data ogłoszenia należą do znaczenia prawnego
     * wersji (kogo i jak powiadomić), więc też są zamrożone (ADR-028 §2).
     */
    public const LOCKED_FIELDS = ['type', 'locale', 'title', 'content', 'effective_from', 'change_kind', 'announced_on'];

    protected $fillable = [
        'fishery_id',
        'type',
        'locale',
        'title',
        'effective_from',
        'change_kind',
        'announced_on',
        'content',
        'required_at_purchase',
        'required_at_registration',
    ];

    protected $attributes = [
        'locale' => 'pl',
        'change_kind' => 'notice',
    ];

    protected $casts = [
        'type' => DocumentType::class,
        'change_kind' => DocumentChangeKind::class,
        'effective_from' => 'date',
        'announced_on' => 'date',
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

    /**
     * Warianty językowe tej samej treści (ADR-028 §1) — wiążący jest wariant w `locale` wersji.
     *
     * @return HasMany<DocumentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(DocumentTranslation::class, 'document_id');
    }

    /**
     * Wariant do pokazania w danym języku: tłumaczenie, gdy istnieje, inaczej treść wiążąca. Zwraca też
     * język, który wędkarz zobaczył — ten trafia do rejestru zgód.
     *
     * @return array{locale: string, title: string, content: string}
     */
    public function variantFor(?string $locale): array
    {
        if ($locale !== null && $locale !== $this->locale) {
            $translation = $this->relationLoaded('translations')
                ? $this->translations->firstWhere('locale', $locale)
                : $this->translations()->where('locale', $locale)->first();

            if ($translation instanceof DocumentTranslation) {
                return ['locale' => $translation->locale, 'title' => $translation->title, 'content' => $translation->content];
            }
        }

        return ['locale' => (string) $this->locale, 'title' => (string) $this->title, 'content' => (string) $this->content];
    }
}
