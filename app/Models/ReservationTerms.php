<?php

namespace App\Models;

use App\Enums\TermsExceptionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Zamrożone warunki rezerwacji, jeden rekord na rezerwację (ADR-026, opcja C).
 *
 * Kolumny niosą wartości do sumowania i filtrowania (kwoty w groszach, waluta, liczba osób),
 * JSON — struktury złożone: rozbicie ceny, polityka zwrotu, parametry stanowiska, usługi,
 * wskazania wersji dokumentów, wyjątki i kontakt z chwili rezerwacji.
 *
 * ⚠️ **Zamrożone znaczy „nie zmienia się niejawnie", nie „nieedytowalne"** (ADR-026 pkt 3). Zmienia je
 * wyłącznie jawna operacja `ReservationBooking`, zawsze z wpisem w kanale `reservations`. Zmiana
 * konfiguracji łowiska nigdy ich nie przepisuje — a zwrot i anulacja liczą się wyłącznie stąd (pkt 5).
 *
 * ⚠️ `schema_version` rośnie przy zmianie kształtu JSON-ów. Brakującego pola nie da się odtworzyć
 * dla rezerwacji już zapisanych, więc czytający musi znać wersję, a nie zgadywać po kluczach.
 */
class ReservationTerms extends Model
{
    use LogsActivity;

    /** Wersja kształtu JSON-ów zapisywanych przez `ReservationTermsSnapshot`. */
    public const SCHEMA_VERSION = 1;

    protected $table = 'reservation_terms';

    protected $fillable = [
        'reservation_id',
        'schema_version',
        'currency',
        'anglers',
        'companions',
        'stay_total_cents',
        'services_total_cents',
        'computed_total_cents',
        'agreed_total_cents',
        'total_cents',
        'price_breakdown',
        'refund_policy',
        'position_snapshot',
        'services',
        'documents',
        'exceptions',
        'contact_snapshot',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'anglers' => 'integer',
        'companions' => 'integer',
        'stay_total_cents' => 'integer',
        'services_total_cents' => 'integer',
        'computed_total_cents' => 'integer',
        'agreed_total_cents' => 'integer',
        'total_cents' => 'integer',
        'price_breakdown' => 'array',
        'refund_policy' => 'array',
        'position_snapshot' => 'array',
        'services' => 'array',
        'documents' => 'array',
        'exceptions' => 'array',
        'contact_snapshot' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(Reservation::LOG_NAME)
            ->logOnly($this->fillable);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Wyjątki danego rodzaju zapisane w warunkach.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exceptionsOf(TermsExceptionKind $kind): array
    {
        return array_values(array_filter(
            $this->exceptions ?? [],
            fn (array $exception): bool => ($exception['kind'] ?? null) === $kind->value,
        ));
    }

    public function hasException(TermsExceptionKind $kind): bool
    {
        return $this->exceptionsOf($kind) !== [];
    }
}
