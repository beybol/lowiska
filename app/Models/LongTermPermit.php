<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LongTermPermit extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active', 
        'description', 
        'valid_from', 
        'valid_to', 
        'fishery_id', 
        'price', 
        'sales_limit',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
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

    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }
}
