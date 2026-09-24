<?php

namespace App\Models;

use App\Enums\ServiceBillingUnit;
use App\Enums\ServiceScope;
use App\Services\AmountFormatter;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AdditionalService extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active',
        'description',
        'fishery_id',
        'price',
        'billing_unit',
        'scope',
        'name',
        'available_count',
    ];

    /**
     * Te same wartości domyślne co w migracji — bez nich świeżo utworzony model nie zna
     * jednostki ani zasięgu, dopóki nie zostanie przeczytany z bazy.
     */
    protected $attributes = [
        'billing_unit' => 'per_night',
        'scope' => 'selected_positions',
    ];

    protected $casts = [
        'billing_unit' => ServiceBillingUnit::class,
        'scope' => ServiceScope::class,
    ];

    /**
     * ⚠️ **Zmiana zasięgu na „całe łowisko" USUWA przypięcia usługi** (razem z `is_required`).
     * Hak modelu, a nie strona formularza, bo niezmiennik „usługa ogólnołowiskowa nie ma przypięć"
     * ma obowiązywać także przy zapisie z pominięciem formularza. Ślad zostaje w dzienniku zmian:
     * wpis o zmianie `scope` i osobny wpis z listą odpiętych stanowisk.
     */
    protected static function booted(): void
    {
        static::saved(function (self $service): void {
            if ($service->scope === ServiceScope::WholeFishery && $service->wasChanged('scope')) {
                $service->dropPins();
            }
        });
    }

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
     * Przypięcia do stanowisk — znaczą coś wyłącznie przy zasięgu „wybrane stanowiska".
     *
     * @return BelongsToMany<Position, $this>
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class)->withPivot('is_required');
    }

    /**
     * Cechy typu flaga, których brak wyłącza usługę na stanowisku (zadanie 020).
     *
     * ⚠️ Cecha usunięta miękko ze słownika NIE trafia do tej relacji (globalny zakres `SoftDeletes`
     * modelu cechy), więc jej wymóg jest pomijany — usunięcie cechy nie może wyłączyć usługi na
     * całym łowisku. Wiersz pośredni zostaje, więc `restore()` cechy przywraca też wymóg.
     *
     * @return BelongsToMany<PositionAttribute, $this>
     */
    public function requiredAttributes(): BelongsToMany
    {
        return $this->belongsToMany(PositionAttribute::class, 'additional_service_required_attribute');
    }

    public function isFree(): bool
    {
        return $this->priceInCents() === 0;
    }

    /**
     * Cena w groszach, z pominięciem akcesora formatującego przecinek.
     */
    public function priceInCents(): ?int
    {
        $raw = $this->getRawOriginal('price') ?? $this->attributes['price'] ?? null;

        return $raw === null ? null : (int) round((float) $raw * 100);
    }

    /**
     * Cena podstawowa z jednostką — „20,00 zł / doba", a przy 0,00 „bezpłatna".
     *
     * ⚠️ Jedyny dom tego tekstu: tabela usług i kalendarz podglądowy biorą go stąd.
     */
    public function priceLabel(?string $currency = null): string
    {
        $cents = $this->priceInCents();

        if ($cents === null) {
            return __('no price');
        }

        if ($cents === 0) {
            return __('free');
        }

        $unit = $this->billing_unit ?? ServiceBillingUnit::PerNight;

        return AmountFormatter::cents($cents, $currency).' / '.$unit->priceSuffix();
    }

    /**
     * Odpina usługę od wszystkich stanowisk i zapisuje to w dzienniku zmian.
     */
    public function dropPins(): int
    {
        $positionIds = $this->positions()->pluck('positions.id')->map(fn ($id): int => (int) $id)->all();

        if ($positionIds === []) {
            return 0;
        }

        $this->positions()->detach();

        activity()
            ->performedOn($this)
            ->event('updated')
            ->withChanges([
                'old' => ['pinned_position_ids' => $positionIds],
                'attributes' => ['pinned_position_ids' => []],
            ])
            ->log('updated');

        return count($positionIds);
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

    /** Usługi, które da się przypiąć do stanowiska — wyłącznie o zasięgu „wybrane stanowiska". */
    #[Scope]
    protected function pinnable(Builder $query): void
    {
        $query->where('scope', ServiceScope::SelectedPositions->value);
    }
}
