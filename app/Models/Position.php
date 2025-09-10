<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Position extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active',
        'name',
        'description',
        'fishery_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }

    public function longTermPermits(): BelongsToMany
    {
        return $this->belongsToMany(LongTermPermit::class);
    }

    public function additionalServices(): BelongsToMany
    {
        return $this->belongsToMany(AdditionalService::class);
    }
}
