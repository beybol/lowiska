<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Country extends Model
{
    use LogsActivity;

    protected $fillable = ['prefix', 'country_name', 'is_active'];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 1);
    }

    #[Scope]
    protected function poland(Builder $query): void
    {
        $query->where('country_name', 'Poland');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable);
    }
}
