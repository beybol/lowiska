<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\SlotType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Okno czasu zajęte na stanowisku przez rezerwację, `[starts_at, ends_at)` w UTC (ADR-025).
 *
 * ⚠️ **Wiersze pisze i usuwa wyłącznie `ReservationBooking`**, pod blokadą wiersza stanowiska.
 * Przedział jest zamrożony — zmiana konfiguracji łowiska go nie przelicza (ADR-025 pkt 2, 10).
 * Bez dziennika zmian: historię niesie rezerwacja, a slot jest czystą zajętością.
 */
class ReservationSlot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reservation_id',
        'position_id',
        'slot_type',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'slot_type' => SlotType::class,
        'starts_at' => UtcDateTime::class,
        'ends_at' => UtcDateTime::class,
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
