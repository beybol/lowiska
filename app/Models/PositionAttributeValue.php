<?php

namespace App\Models;

use App\Enums\PositionAttributeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wartość cechy na konkretnym stanowisku (ADR-011, opcja A).
 *
 * ⚠️ BRAK WIERSZA to trzeci stan, nie „nie". Cecha niewypełniona oznacza „nikt się
 * nie wypowiedział" i nie bierze udziału w filtrowaniu w żadną stronę — dlatego
 * `value_flag = false` i brak rekordu MUSZĄ być odróżnialne.
 *
 * ⚠️ Model nie ma własnej polityki ani zasobu: żyje przez stanowisko i edytuje się
 * go z jego formularza albo akcją zbiorczą. Dostępu pilnuje `PositionPolicy`
 * (`docs/conventions/autoryzacja.md` §5). Nie ma też `SoftDeletes` — wycofana
 * wartość cechy nie niesie historii, a indeks unikalny musiałby ją omijać.
 */
class PositionAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'position_id',
        'position_attribute_id',
        'value_flag',
        'value_number',
        'position_attribute_option_id',
    ];

    protected $casts = [
        'value_flag' => 'boolean',
        'value_number' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo<PositionAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(PositionAttribute::class, 'position_attribute_id');
    }

    /**
     * @return BelongsTo<PositionAttributeOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PositionAttributeOption::class, 'position_attribute_option_id');
    }

    /**
     * Odczyt wartości właściwej dla typu cechy — jedno miejsce, które wie,
     * z której z trzech kolumn korzystać.
     */
    public function typedValue(PositionAttributeType $type): bool|string|int|null
    {
        return match ($type) {
            PositionAttributeType::Flag => $this->value_flag,
            PositionAttributeType::Number => $this->value_number,
            PositionAttributeType::Choice => $this->position_attribute_option_id,
        };
    }
}
