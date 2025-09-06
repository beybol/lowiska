<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active',
        'name',
        'description',
        'fishery_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }
}
