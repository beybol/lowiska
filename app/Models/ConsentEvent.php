<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ConsentChannel;
use App\Enums\ConsentContext;
use App\Enums\ConsentDecision;
use App\Enums\ConsentKind;
use App\Enums\ConsentSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Zdarzenie w rejestrze zgód i akceptacji (zadanie 049, ADR-028).
 *
 * ⚠️ **Tylko dopisywanie.** Wiersz jest dowodem — kto, co (wersja dokumentu i wariant językowy albo nadawca
 * z kanałem i wersją klauzuli), kiedy, w jakim kontekście i z jakiego adresu IP. Edycja i usunięcie rzucają
 * wyjątek; wycofanie zgody to NOWE zdarzenie `withdrawn`. Zapisuje wyłącznie `ConsentRegistry`.
 */
class ConsentEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'kind',
        'decision',
        'context',
        'document_id',
        'locale',
        'sender',
        'fishery_id',
        'channel',
        'clause_version',
        'fishery_angler_id',
        'ip_address',
        'occurred_at',
    ];

    protected $casts = [
        'kind' => ConsentKind::class,
        'decision' => ConsentDecision::class,
        'context' => ConsentContext::class,
        'sender' => ConsentSender::class,
        'channel' => ConsentChannel::class,
        'occurred_at' => UtcDateTime::class,
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent events are append-only.'));
        static::deleting(fn () => throw new LogicException('Consent events are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
