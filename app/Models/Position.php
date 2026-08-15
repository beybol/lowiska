<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Position extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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
        return $this->belongsToMany(AdditionalService::class)
            ->withPivot('is_required');
    }

    #[Scope]
    protected function isActive(Builder $query): void
    {
        $query->where('is_active', 1);
    }
}
