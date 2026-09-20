<?php

namespace App\Models;

use App\Enums\BlockEffect;
use App\Enums\SelectionKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wpis o dostępności: blokada sprzedaży ALBO zawieszenie cechy — jeden byt, jeden
 * kształt (zbiór stanowisk, zakres dat, powód, widoczność), różny wyłącznie skutek.
 *
 * ⚠️ Zbiór stanowisk jest ZMATERIALIZOWANY w `availability_block_position`. `selection_kind`
 * i `selection_label` mówią tylko, jak go wybrano, i nie są rozwiązywane przy odczycie.
 *
 * ⚠️ Model NIE jest źródłem prawdy o dostępności — jest nim `PositionAvailability`
 * (ADR-012). Zakresy niżej służą tej usłudze i panelowi; nie buduj na nich własnego
 * wyliczenia obok.
 */
class AvailabilityBlock extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fishery_id',
        'effect',
        'position_attribute_id',
        'starts_on',
        'ends_on',
        'reason',
        'reason_visible',
        'selection_kind',
        'selection_label',
    ];

    protected $casts = [
        'effect' => BlockEffect::class,
        'selection_kind' => SelectionKind::class,
        'starts_on' => 'date',
        'ends_on' => 'date',
        'reason_visible' => 'boolean',
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
     * @return BelongsTo<PositionAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(PositionAttribute::class, 'position_attribute_id');
    }

    /**
     * @return BelongsToMany<Position, $this>
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'availability_block_position');
    }

    /**
     * Wpisy, które jeszcze się nie skończyły — obowiązujące dziś albo w przyszłości.
     */
    #[Scope]
    protected function notEndedBefore(Builder $query, CarbonInterface|string $date): void
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $query->where(function (Builder $query) use ($day): void {
            $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day);
        });
    }

    #[Scope]
    protected function withEffect(Builder $query, BlockEffect $effect): void
    {
        $query->where('effect', $effect->value);
    }

    #[Scope]
    protected function wholeFishery(Builder $query): void
    {
        $query->where('selection_kind', SelectionKind::Fishery->value);
    }
}
