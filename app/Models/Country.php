<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

class Country extends Model
{
    protected $fillable = ['prefix', 'country_name', 'is_active'];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 1);
    }
}
