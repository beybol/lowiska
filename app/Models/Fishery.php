<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Fishery extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'user_id',
        'state_id',
        'company_id',
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
        'records',
        'map_image_path',
        'gallery_images',
        'currency_id',
        'bank_account_number',
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

    #[Scope]
    public function forCurrentUser(Builder $query): void
    {
        if (auth()->check()) {
            $query->where('user_id', auth()->id());
        }
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<LongTermPermit, $this>
     */
    public function longTermPermits(): HasMany
    {
        return $this->hasMany(LongTermPermit::class);
    }

    /**
     * @return HasMany<AdditionalService, $this>
     */
    public function additionalServices(): HasMany
    {
        return $this->hasMany(AdditionalService::class);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
