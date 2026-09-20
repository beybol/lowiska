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
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
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
}
