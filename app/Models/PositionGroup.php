<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Grupa stanowisk — ETYKIETA, nośnik opisu i zapisane zaznaczenie.
 *
 * ⚠️ Grupa **nie niesie cech ani żadnego stanu** i nie jest poziomem hierarchii.
 * Nie ma tu dziedziczenia ani reguły „co wygrywa", więc łowisko nieużywające grup
 * zachowuje się identycznie jak przed ich wprowadzeniem. Wygodę ustawiania cech
 * hurtem daje akcja zbiorcza, która zapisuje wartość wprost na każdym stanowisku.
 *
 * ⚠️ Grupa nie jest jednostką sprzedaży — kupuje się stanowisko.
 */
class PositionGroup extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fishery_id',
        'name',
        'description',
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
     * @return BelongsToMany<Position, $this>
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'group_position');
    }
}
