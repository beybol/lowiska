<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dopuszczalna wartość cechy typu `choice`.
 *
 * ⚠️ Model świadomie nie ma własnej polityki ani zasobu Filamenta — żyje przez
 * cechę i edytuje się go `Repeaterem` w jej formularzu. Dostępu pilnuje
 * `PositionAttributePolicy` (`docs/conventions/autoryzacja.md` §5).
 */
class PositionAttributeOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'position_attribute_id',
        'name',
        'sort_order',
    ];

    /**
     * @return BelongsTo<PositionAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(PositionAttribute::class, 'position_attribute_id');
    }
}
