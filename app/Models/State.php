<?php

namespace App\Models;

use App\Services\PortalSlugs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class State extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'slug', 'country_id'];

    /**
     * ⚠️ Slug województwa jest STAŁY — powstaje przy utworzeniu z polskiej nazwy i zmiana nazwy
     * go nie rusza; admin może go zmienić ręcznie w zasobie województw (zadanie 030).
     */
    protected static function booted(): void
    {
        static::creating(function (State $state): void {
            if (blank($state->slug)) {
                $state->slug = PortalSlugs::forState((string) $state->name, (int) $state->country_id);
            }
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable);
    }

    #[Scope]
    protected function getByName(Builder $query, string $name): void
    {
        $query->where('name', $name);
    }
}
