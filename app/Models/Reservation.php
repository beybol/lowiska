<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\CancellationReason;
use App\Enums\ConfirmationSource;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Rezerwacja stanowiska (zadanie 039, ADR-024…026).
 *
 * ⚠️ **Zapis wyłącznie przez `ReservationBooking`** — ta sama klasa pisze sloty, warunki i pozycje
 * usług, pod blokadą wiersza stanowiska (ADR-025 pkt 4). Utworzenie rezerwacji z pominięciem
 * serwisu nie zajmie stanowiska i ominie sprawdzenie dostępności; pilnuje tego test.
 *
 * Zmiany trafiają do dziennika zmian w osobnym kanale `reservations`, który nie podlega czyszczeniu
 * (ADR-026 pkt 4, `dziennik-zmian.md`).
 */
class Reservation extends Model
{
    use HasFactory, LogsActivity;

    /** Kanał dziennika zmian rezerwacji — wyłączony z czyszczenia (`CleanActivityLogKeepingRetained`). */
    public const LOG_NAME = 'reservations';

    protected $fillable = [
        'fishery_id',
        'position_id',
        'fishery_angler_id',
        'status',
        'payment_status',
        'starts_at',
        'ends_at',
        'first_night_on',
        'nights',
        'confirm_by',
        'confirmed_at',
        'confirmation_source',
        'source',
        'created_by',
        'internal_note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'cancel_message',
        'expired_at',
        'needs_contact_at',
        'contacted_at',
    ];

    protected $hidden = [
        'manage_token_hash',
    ];

    protected $casts = [
        'status' => ReservationStatus::class,
        'payment_status' => PaymentStatus::class,
        'confirmation_source' => ConfirmationSource::class,
        'source' => ReservationSource::class,
        'cancel_reason' => CancellationReason::class,
        'first_night_on' => 'immutable_date',
        'nights' => 'integer',
        'starts_at' => UtcDateTime::class,
        'ends_at' => UtcDateTime::class,
        'confirm_by' => UtcDateTime::class,
        'confirmed_at' => UtcDateTime::class,
        'cancelled_at' => UtcDateTime::class,
        'expired_at' => UtcDateTime::class,
        'needs_contact_at' => UtcDateTime::class,
        'contacted_at' => UtcDateTime::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::LOG_NAME)
            ->logOnly($this->fillable);
    }

    /**
     * @return BelongsTo<Fishery, $this>
     */
    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    /**
     * @return BelongsTo<FisheryAngler, $this>
     */
    public function angler(): BelongsTo
    {
        return $this->belongsTo(FisheryAngler::class, 'fishery_angler_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<ReservationSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ReservationSlot::class);
    }

    /**
     * @return HasMany<ReservationServiceItem, $this>
     */
    public function serviceItems(): HasMany
    {
        return $this->hasMany(ReservationServiceItem::class);
    }

    /**
     * @return HasOne<ReservationTerms, $this>
     */
    public function terms(): HasOne
    {
        return $this->hasOne(ReservationTerms::class);
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::active()));
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Pobyt już się rozpoczął, a jeszcze nie skończył — wyliczenie, nie stan (zadanie 039). */
    public function isInProgress(?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        return $this->isActive() && $this->starts_at <= $now && $this->ends_at > $now;
    }

    public function hasStarted(?CarbonInterface $now = null): bool
    {
        return $this->starts_at <= CarbonImmutable::instance($now ?? now());
    }
}
