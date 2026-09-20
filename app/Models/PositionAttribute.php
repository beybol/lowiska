<?php

namespace App\Models;

use App\Enums\PositionAttributeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cecha stanowiska — pozycja SŁOWNIKA WSPÓLNEGO dla całego portalu.
 *
 * ⚠️ Słownik jest wyłącznie w rękach administratora i na tym polega jego sens:
 * cecha znaczy to samo na każdym łowisku, więc da się po niej filtrować przez
 * wszystkie. Cechy własne łowiska są odrzucone co do zasady, nie odłożone.
 *
 * ⚠️ Cechy powstają wyłącznie dla rzeczy NIEKUPOWALNYCH. Wszystko, co wędkarz
 * dokupuje, jest usługą dodatkową (zadanie 020).
 */
class PositionAttribute extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'unit',
        'is_filterable',
    ];

    protected $casts = [
        'type' => PositionAttributeType::class,
        'is_filterable' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    /**
     * @return HasMany<PositionAttributeOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PositionAttributeOption::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<PositionAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(PositionAttributeValue::class);
    }
}
