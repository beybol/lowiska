<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

class AdditionalService extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active', 
        'description', 
        'fishery_id', 
        'price', 
        'name',
        'available_count',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }

    public function setPriceAttribute($value)
    {
        if ($value !== null && is_string($value)) {
            $this->attributes['price'] = str_replace(',', '.', $value);
        } else {
            $this->attributes['price'] = $value;
        }
    }

    public function getPriceAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        
        $language = app()->getLocale();

        if ($language === 'pl') {
            return str_replace('.', ',', $value);
        }

        return $value;
    }

    #[Scope]
    protected function forFishery(Builder $query, int $fisheryId): void
    {
        $query->where('fishery_id', $fisheryId);
    }

    #[Scope]
    protected function isActive(Builder $query): void
    {
        $query->where('is_active', 1);
    }
}
