<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Okres, w którym łowisko w ogóle sprzedaje. Poza nim nie da się kupić nic,
 * także wtedy, gdy stanowisko jest wolne (zadanie 015, F2).
 *
 * ⚠️ Model świadomie NIE ma własnej polityki ani zasobu Filamenta — dostępu
 * pilnuje `FisheryPolicy`, bo okres istnieje wyłącznie przez łowisko.
 * Niezmiennik i droga wyjścia: `docs/conventions/autoryzacja.md` §5.
 */
class SalePeriod extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fishery_id',
        'name',
        'starts_on',
        'ends_on',
        'presale_opens_on',
        'presale_closes_on',
        'presale_min_nights',
        'presale_whole_terms_bypass_min_nights',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'presale_opens_on' => 'date',
        'presale_closes_on' => 'date',
        'presale_whole_terms_bypass_min_nights' => 'boolean',
    ];

    /**
     * Domyślne lustro wartości z bazy, tym samym zabiegiem co w `Fishery`: `create()`
     * nie czyta z powrotem kolumn wypełnionych domyślną wartością po stronie MySQL-a,
     * więc bez tego świeżo utworzony okres miał flagę `null` zamiast `true`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'presale_whole_terms_bypass_min_nights' => true,
    ];

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
     * Czy przedsprzedaż tego okresu jest WŁĄCZONA — czyli obie daty wypełnione.
     *
     * ⚠️ Nie ma osobnej kolumny-przełącznika: stan wynika z danych, a przełącznik
     * w formularzu jest polem, nie kolumną (`panel-wlasciciela.md` §6).
     */
    public function hasPresale(): bool
    {
        return $this->presale_opens_on !== null && $this->presale_closes_on !== null;
    }
}
