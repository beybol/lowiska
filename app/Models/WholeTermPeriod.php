<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Święto sprzedawane wyłącznie w całości — spoiwo JEDNORAZOWE, datowane (ADR-013).
 *
 * ⚠️ `first_day_on` i `last_day_on` to DNI ROZPOCZĘCIA dób, nie granice okna.
 * `last_day_on` wskazuje dobę OBJĘTĄ, więc święto 30.04–02.05 obejmuje trzy doby,
 * podczas gdy okres sprzedaży o tych samych datach sprzedaje dwie. Nazwy różnią się
 * od `sale_periods` właśnie dlatego, żeby nikt nie skopiował tamtej logiki granic
 * (`docs/conventions/dostepnosc.md` §4).
 *
 * ⚠️ Model świadomie NIE ma własnej polityki, zasobu Filamenta ani uprawnień Shielda.
 * Nie jest to oszczędność: `shield:generate` wyprowadza uprawnienia z zarejestrowanych
 * zasobów, więc polityka pytająca o `'view_any:whole_term_period'` nazwałaby uprawnienie,
 * którego generator nigdy nie utworzy, i wywróciłaby `ShieldPermissionNamesTest`.
 * Dostępu pilnuje `FisheryPolicy` — święto istnieje wyłącznie przez łowisko.
 * Niezmiennik i droga wyjścia: `docs/conventions/autoryzacja.md` §5.
 */
class WholeTermPeriod extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fishery_id',
        'name',
        'first_day_on',
        'last_day_on',
    ];

    protected $casts = [
        'first_day_on' => 'date',
        'last_day_on' => 'date',
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
     * Czy dzień rozpoczęcia doby wpada w to święto.
     *
     * ⚠️ Porównanie na DATACH (`Y-m-d`), nie na momentach — święto jest zbiorem dni
     * rozpoczęcia dób, a nie oknem czasowym. Porównanie momentów wciągałoby tutaj
     * strefę czasową, której ten model nie zna i znać nie musi.
     */
    public function coversDayStartingOn(string $date): bool
    {
        return $date >= $this->first_day_on->toDateString()
            && $date <= $this->last_day_on->toDateString();
    }
}
