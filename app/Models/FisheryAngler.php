<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\AnglerLinkStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Relacja łowisko ↔ wędkarz (ADR-024). Łowisko widzi wędkarza WYŁĄCZNIE przez swoje rekordy relacji.
 *
 * ⚠️ Operator nigdy nie zapisuje do `users` — pisze do `given_*` relacji. Powiązanie z kontem
 * (`user_id`, `Linked`) robi wyłącznie sam wędkarz linkiem z maila (zadanie 043).
 *
 * ⚠️ Token powiązania nie jest wypełnialny i nie trafia do dziennika zmian: ustawia go jawnie
 * przepływ z zadania 043 (`forceFill`), w bazie leży tylko hash.
 */
class FisheryAngler extends Model
{
    use HasFactory, LogsActivity;

    public const LOG_NAME = Reservation::LOG_NAME;

    protected $fillable = [
        'fishery_id',
        'user_id',
        'status',
        'given_name',
        'given_email',
        'given_phone',
        'phone_override',
        'notes',
    ];

    protected $hidden = [
        'link_token_hash',
        'link_email',
    ];

    protected $attributes = [
        'status' => 'unlinked',
    ];

    protected $casts = [
        'status' => AnglerLinkStatus::class,
        'link_expires_at' => UtcDateTime::class,
        'linked_at' => UtcDateTime::class,
        'revoked_at' => UtcDateTime::class,
    ];

    protected static function booted(): void
    {
        // Klucz unikalności adresu w łowisku liczy się z każdej ścieżki zapisu, nie z formularza.
        static::saving(function (self $angler): void {
            $angler->given_email_normalized = self::normalizeEmail($angler->given_email);
        });
    }

    /**
     * Jedyne miejsce normalizacji adresu e-mail relacji (ADR-024 pkt 1).
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Bez pustych wpisów "updated" przy zmianie tokenu (047, H3) — tokeny nie są wypełnialne.
        return LogOptions::defaults()->useLogName(self::LOG_NAME)->logOnly($this->fillable)->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Fishery, $this>
     */
    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Dane kontaktowe, które łowisko widzi DZIŚ (ADR-024 pkt 2): niepowiązana — dane od operatora;
     * powiązana — dane konta z nadpisaniem telefonu; cofnięta — nic (zostaje snapshot rezerwacji).
     *
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    public function effectiveContact(): array
    {
        return match ($this->status) {
            AnglerLinkStatus::Linked => [
                'name' => $this->user?->name !== null
                    ? trim($this->user->name.' '.($this->user->surname ?? ''))
                    : $this->given_name,
                'email' => $this->user->email ?? $this->given_email,
                'phone' => $this->phone_override ?? $this->user->phone ?? $this->given_phone,
            ],
            AnglerLinkStatus::Revoked => ['name' => null, 'email' => null, 'phone' => null],
            default => [
                'name' => $this->given_name,
                'email' => $this->given_email,
                'phone' => $this->given_phone,
            ],
        };
    }

    public function effectiveEmail(): ?string
    {
        return $this->effectiveContact()['email'];
    }
}
