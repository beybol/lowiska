<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Services\FishingDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Reguła cenowa — jedna pozycja listy, z której składa się cennik (ADR-014).
 *
 * ⚠️ **Pusty warunek znaczy „bez warunku na tej osi", NIE „warunek fałszywy".** Reguła `rate`
 * bez ani jednego warunku jest stawką bazową łowiska; oba łowiska klienta obchodzą się jedną
 * taką regułą plus jedną dopłatą.
 *
 * ⚠️ **Dwa wymiary czasu i nie wolno ich zlać.** `effective_*` mierzy się wobec DZISIEJSZEJ DATY
 * (czy ten zapis bierze udział w wycenie), a `first_day_on`/`last_day_on` — wobec WYCENIANEJ DOBY
 * (których dób reguła dotyczy). Obie granice są domknięte.
 *
 * ⚠️ Model świadomie NIE ma własnej polityki ani zasobu Filamenta — jak `SalePeriod` (015)
 * i `WholeTermPeriod` (017). `shield:generate` wyprowadza uprawnienia z zarejestrowanych zasobów,
 * więc polityka pytająca o `'view_any:price_rule'` wywróciłaby `ShieldPermissionNamesTest`.
 * Dostępu pilnuje `FisheryPolicy` (`docs/conventions/autoryzacja.md` §5).
 */
class PriceRule extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fishery_id',
        'kind',
        'label',
        'amount',
        'priority',
        'is_suspended',
        'effective_from',
        'effective_to',
        'weekdays',
        'first_day_on',
        'last_day_on',
        'anglers_count',
        'participant_role',
    ];

    protected $casts = [
        'kind' => PriceRuleKind::class,
        'participant_role' => ParticipantRole::class,
        'is_suspended' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'first_day_on' => 'date',
        'last_day_on' => 'date',
        'weekdays' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }

    /**
     * @return BelongsTo<Fishery, $this>
     */
    public function fishery(): BelongsTo
    {
        return $this->belongsTo(Fishery::class);
    }

    /**
     * Kwota w groszach.
     *
     * ⚠️ Wycena liczy w **liczbach całkowitych**, nie na `float`. Obniżka przedsprzedażowa
     * zaokrągla się raz na dobę i musi odróżnić 14,01 zł od 14,02 zł — arytmetyka
     * zmiennoprzecinkowa na takich kwotach nie daje na to gwarancji.
     */
    public function amountInCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }

    /**
     * Czy ten ZAPIS bierze udział w wycenie danego dnia (wymiar `effective_*`).
     *
     * Granice domknięte: reguła obowiązuje także w dniu `effective_to`.
     */
    public function isEffectiveOn(CarbonImmutable $date): bool
    {
        if ($this->is_suspended) {
            return false;
        }

        $day = $date->toDateString();

        if ($this->effective_from !== null && $day < $this->effective_from->toDateString()) {
            return false;
        }

        return $this->effective_to === null || $day <= $this->effective_to->toDateString();
    }

    /**
     * Czy warunki reguły są spełnione dla TEJ doby, roli i obsady.
     *
     * ⚠️ Sprawdzane osobno dla KAŻDEJ doby pobytu (K3/P2), nie „całe albo wcale": pobyt śr–pt
     * przy dopłacie „czw–nd" dostaje ją za czwartek i piątek, a nie za środę.
     *
     * ⚠️ `anglers_count` porównuje się przez RÓWNOŚĆ z faktyczną obsadą z zapytania — nigdy
     * z `positions.max_anglers`, która jest pojemnością stanowiska i kusi wyłącznie nazwą
     * (zadanie 018, rozstrzygnięcie 24).
     */
    public function matches(FishingDay $night, ParticipantRole $role, int $anglersCount): bool
    {
        $day = $night->startsOn->toDateString();

        $weekdays = $this->weekdayNumbers();

        if ($weekdays !== [] && ! in_array((int) $night->startsOn->isoWeekday(), $weekdays, true)) {
            return false;
        }

        // Granica domknięta: warunek obejmuje także dobę rozpoczynającą się `last_day_on`.
        if ($this->first_day_on !== null && $day < $this->first_day_on->toDateString()) {
            return false;
        }

        if ($this->last_day_on !== null && $day > $this->last_day_on->toDateString()) {
            return false;
        }

        if ($this->anglers_count !== null && (int) $this->anglers_count !== $anglersCount) {
            return false;
        }

        return $this->participant_role === null || $this->participant_role === $role;
    }

    /**
     * Szczegółowość reguły liczona OSIAMI, nie polami: od 0 (stawka bazowa) do 4.
     *
     * ⚠️ Osi jest cztery — dni tygodnia, zakres dat, liczba łowiących, rola — a oś jest
     * niepusta, gdy niesie **jakikolwiek** warunek. Zakres z jednym otwartym końcem liczy się
     * jako JEDNA oś, nie pół ani dwie. Licząc pola zamiast osi, szczegółowość zależałaby od
     * tego, czy operator domknął przedział (ADR-014).
     */
    public function specificity(): int
    {
        $axes = 0;

        if ($this->weekdayNumbers() !== []) {
            $axes++;
        }

        if ($this->first_day_on !== null || $this->last_day_on !== null) {
            $axes++;
        }

        if ($this->anglers_count !== null) {
            $axes++;
        }

        if ($this->participant_role !== null) {
            $axes++;
        }

        return $axes;
    }

    /**
     * Dni ISO-8601 rozpoczęcia doby, na których reguła obowiązuje.
     *
     * ⚠️ Rzutowanie na `int` jest konieczne: kolumna JSON oddaje to, co w niej zapisano,
     * a formularz Filamenta zapisuje tam łańcuchy — ta sama pułapka co przy `weekend_days`
     * łowiska (017).
     *
     * @return array<int, int>
     */
    public function weekdayNumbers(): array
    {
        if (! is_array($this->weekdays)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $day): int => (int) $day, $this->weekdays));
    }
}
