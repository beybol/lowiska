<?php

namespace App\Models;

use App\Enums\PositionStatus;
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

class Position extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'fishery_id',
        'max_anglers',
        'max_people',
        'status',
    ];

    protected $casts = [
        'status' => PositionStatus::class,
    ];

    /**
     * Domyślne lustro wartości z bazy, żeby świeżo utworzony model miał stan także
     * PRZED odświeżeniem — `create()` nie czyta z powrotem kolumn wypełnionych
     * domyślną wartością po stronie MySQL-a.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
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
     * @return BelongsToMany<LongTermPermit, $this>
     */
    public function longTermPermits(): BelongsToMany
    {
        return $this->belongsToMany(LongTermPermit::class);
    }

    /**
     * @return BelongsToMany<AdditionalService, $this>
     */
    public function additionalServices(): BelongsToMany
    {
        return $this->belongsToMany(AdditionalService::class)
            ->withPivot('is_required');
    }

    /**
     * @return BelongsToMany<PositionGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(PositionGroup::class, 'group_position');
    }

    /**
     * @return HasMany<PositionAttributeValue, $this>
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(PositionAttributeValue::class);
    }

    /**
     * @return BelongsToMany<AvailabilityBlock, $this>
     */
    public function availabilityBlocks(): BelongsToMany
    {
        return $this->belongsToMany(AvailabilityBlock::class, 'availability_block_position');
    }

    /**
     * Stanowiska będące w sprzedaży — zastępuje dawny zakres `isActive()`.
     *
     * ⚠️ To jest stan WŁASNY stanowiska, decyzja operatora. Nie odpowiada na pytanie
     * „czy da się tu łowić w konkretnym terminie" — dostępność w czasie składa
     * usługa z zadania 016 z blokad, okresów sprzedaży i tego stanu.
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('status', PositionStatus::Available->value);
    }
}
