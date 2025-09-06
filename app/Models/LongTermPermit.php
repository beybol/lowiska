<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\SoftDeletes;

class LongTermPermit extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'is_active', 
        'description', 
        'valid_from', 
        'valid_to', 
        'fishery_id', 
        'price', 
        'sales_limit',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }
}
