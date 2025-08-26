<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Fishery extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'user_id',
        'state_id',
        'town',
        'street',
        'building_number',
        'directions',
        'description',
        'zip_code',
        'area',
        'avg_depth',
        'max_depth',
        'positions_count',
        'dominant_fish_id',
        'fish_id',
        'fishery_type_id',
        'fishing_method_id',
        'convenience_id',
        'records',
        'map_image_path',
        'gallery_images'
    ];

    protected $casts = ['gallery_images' => 'array'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function conveniences(): BelongsToMany
    {
        return $this->belongsToMany(Convenience::class);
    }

    public function fisheryTypes(): BelongsToMany
    {
        return $this->belongsToMany(FisheryType::class);
    }

    public function fishingMethods(): BelongsToMany
    {
        return $this->belongsToMany(FishingMethod::class);
    }

    public function fish(): BelongsToMany
    {
        return $this->belongsToMany(Fish::class);
    }

    public function dominantFish(): BelongsTo
    {
        return $this->belongsTo(Fish::class);
    }
}
