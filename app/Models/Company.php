<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Company extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'is_verified',
        'name',
        'tin',
        'renae',
        'street',
        'house_number',
        'flat_number',
        'postal_code',
        'city',
        'state_id',
        'cso_response',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable);
    }

    #[Scope]
    public function forCurrentUser(Builder $query): void
    {
        if (auth()->check()) {
            $query->where('user_id', auth()->id());
        }
    }

    #[Scope]
    public function findByNumber(Builder $query, string $tin, string $renae): void
    {
        $query->where('tin', $tin)
            ->orWhere('renae', $renae);
    }
}
