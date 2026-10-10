<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egzemplarze usługi zajęte przez rezerwację w przedziale pobytu (ADR-025 pkt 7).
 *
 * ⚠️ To wyłącznie ZAJĘTOŚĆ, liczona względem `available_count` usługi pod blokadą wiersza usługi.
 * Cena i opis pozycji są zamrożone w `reservation_terms.services`. Egzemplarz zajmuje wszystkie
 * doby pobytu przy obu jednostkach rozliczenia (`dostepnosc.md` §5). Pisze wyłącznie `ReservationBooking`.
 */
class ReservationServiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reservation_id',
        'additional_service_id',
        'quantity',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
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
     * @return BelongsTo<AdditionalService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(AdditionalService::class, 'additional_service_id')->withTrashed();
    }
}
