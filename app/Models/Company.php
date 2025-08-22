<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Models\User;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use LogsActivity, HasFactory;

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
}
