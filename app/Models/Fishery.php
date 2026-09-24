<?php

namespace App\Models;

use App\Enums\PriceRuleKind;
use App\Enums\SaleMode;
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
        'sale_mode',
        'day_start_time',
        'day_end_time',
        'timezone',
        'min_nights',
        'max_nights',
        'weekend_days',
        'sale_horizon_days',
        'refund_policy',
        'fishing_license_required',
        'rods_included',
        'no_kill',
        'campfires_banned',
    ];

    protected $casts = [
        'gallery_images' => 'array',
        'sale_mode' => SaleMode::class,
        // Zbiór dni ISO-8601 rozpoczęcia dób składających się na weekend sprzedawany
        // w całości. Kolumna JSON, nie tabela — uzasadnienie w migracji (zadanie 017).
        'weekend_days' => 'array',
        // Progi zwrotu `{days, percent}` — kolumna JSON wzorem `weekend_days` (zadanie 021).
        // Pusta = „polityka nieustawiona"; czyta je `RefundPolicy`.
        'refund_policy' => 'array',
        // ⚠️ Trzy flagi z trzecim stanem: `null` = „nie podano", nie „nie" (zadanie 021).
        // Formularz dostaje je jako 1/0 — `EditFishery::mutateFormDataBeforeFill()`.
        'fishing_license_required' => 'boolean',
        'no_kill' => 'boolean',
        'campfires_banned' => 'boolean',
        'rods_included' => 'integer',
    ];

    /**
     * Domyślne lustro wartości z bazy, żeby świeżo utworzony model miał tryb
     * sprzedaży także PRZED odświeżeniem z bazy — `create()` nie czyta z powrotem
     * kolumn wypełnionych domyślną wartością po stronie MySQL-a.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sale_mode' => 'daily_period',
        'timezone' => 'Europe/Warsaw',
    ];

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
    protected function forCurrentUser(Builder $query): void
    {
        // ⚠️ Fail-CLOSED. Wcześniej brak zalogowanego użytkownika oznaczał, że scope
        // nie dokłada NICZEGO — czyli prymityw, który cała reszta kodu traktuje jak
        // bramkę, przy gościu przepuszczał wszystko. Trasy paneli są za `Authenticate`,
        // więc dziś nieosiągalne, ale to nie jest powód, żeby zostawiać fail-open.
        if (! auth()->check()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where('user_id', auth()->id());
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
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

    /**
     * Wersje dokumentów łowiska — regulaminu i polityki prywatności (zadanie 021, ADR-017).
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<SalePeriod, $this>
     */
    public function salePeriods(): HasMany
    {
        return $this->hasMany(SalePeriod::class);
    }

    /**
     * @return HasMany<PositionGroup, $this>
     */
    public function positionGroups(): HasMany
    {
        return $this->hasMany(PositionGroup::class);
    }

    /**
     * @return HasMany<AvailabilityBlock, $this>
     */
    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(AvailabilityBlock::class);
    }

    /**
     * Święta sprzedawane wyłącznie w całości (zadanie 017).
     *
     * @return HasMany<WholeTermPeriod, $this>
     */
    public function wholeTermPeriods(): HasMany
    {
        return $this->hasMany(WholeTermPeriod::class);
    }

    /**
     * Cennik łowiska — jedna lista, dwa rodzaje reguł (zadanie 018, ADR-014).
     *
     * @return HasMany<PriceRule, $this>
     */
    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    /**
     * Stawki — podzbiór cennika renderowany osobnym repeaterem.
     *
     * ⚠️ Relacja zawężona po `kind`, bo panel prowadzi dwie listy: operator myśli o stawkach
     * i dopłatach osobno. Eloquent NIE wypełnia wartości z `where()` przy tworzeniu przez
     * relację, więc `kind` ustawia się jawnie w hooku repeatera — inaczej nowy wiersz
     * zapisałby się bez rodzaju i zniknął z obu list.
     *
     * @return HasMany<PriceRule, $this>
     */
    public function rateRules(): HasMany
    {
        return $this->hasMany(PriceRule::class)->where('kind', PriceRuleKind::Rate->value);
    }

    /**
     * @return HasMany<PriceRule, $this>
     */
    public function surchargeRules(): HasMany
    {
        return $this->hasMany(PriceRule::class)->where('kind', PriceRuleKind::Surcharge->value);
    }
}
