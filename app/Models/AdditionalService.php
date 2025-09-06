<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdditionalService extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active', 
        'description', 
        'fishery_id', 
        'price', 
        'name',
        'available_count',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }
}
