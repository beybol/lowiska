<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\State;

class Company extends Model
{
    use LogsActivity;

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
}
