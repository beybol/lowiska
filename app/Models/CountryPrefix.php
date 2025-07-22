<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class CountryPrefix extends Model
{
    protected $fillable = ['prefix', 'country_name'];
}
