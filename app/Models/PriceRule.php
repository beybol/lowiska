<?php

namespace App\Models;

use App\Enums\PriceRuleKind;
use App\Enums\SurchargeAudience;
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
 * ⚠️ **Osie warunku rozkładają się ASYMETRYCZNIE** (ADR-014, sekcja „Aktualizacja"):
 * - **stawka** (`rate`) zna wyłącznie zakres dat;
 * - **dopłata** (`surcharge`) zna daty, dni tygodnia, obsadę i `applies_to`.
 *
 * Kto chce różnicować cenę dniami tygodnia, robi to dopłatą — stawka tego nie potrafi i to
 * jest zamierzone. Zniknęły też priorytet i szczegółowość: nachodzenie rozstrzyga się na
 * korzyść wędkarza, czyli po najniższej kwocie.
 *
 * ⚠️ **Jest tylko JEDEN wymiar czasu.** `first_day_on`/`last_day_on` mierzy się wobec
 * WYCENIANEJ DOBY — mówią, których dób reguła dotyczy. Obie granice są domknięte.
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
        'amount_companion',
        'is_suspended',
        'first_day_on',
        'last_day_on',
        'weekdays',
        'anglers_count',
        'applies_to',
    ];

    protected $casts = [
        'kind' => PriceRuleKind::class,
        'applies_to' => SurchargeAudience::class,
        'is_suspended' => 'boolean',
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
     * Kwota za osobę towarzyszącą w groszach — albo `null`, gdy stawka jej nie ma.
     *
     * ⚠️ **`null` znaczy BRAK CENY, nie cenę zerową.** Zapytanie z osobą towarzyszącą o dobę,
     * której wygrana stawka nie ma tej kwoty, jest ODMOWĄ (`NoCompanionPrice`) — dokładnie jak
     * brak pasującej stawki jest odmową. Darmowa towarzysząca to `0,00` wpisane świadomie,
     * a nie puste pole, którego operator nie zauważył.
     */
    public function companionAmountInCents(): ?int
    {
        if ($this->amount_companion === null) {
            return null;
        }

        return (int) round(((float) $this->amount_companion) * 100);
    }

    /**
     * Czy ta STAWKA obowiązuje w dobie zaczynającej się tego dnia.
     *
     * ⚠️ Stawka nie zna ani dni tygodnia, ani obsady, ani roli — wyłącznie daty. Gdyby
     * kiedykolwiek przybyło jej warunków, wróciłoby pytanie „która stawka wygrywa", które
     * przedefiniowanie z 22.09.2026 usunęło razem z priorytetami.
     *
     * ⚠️ Pytanie zadaje się samą datą, bez konstruowania doby, bo analiza martwych stawek
     * (`PricingConfigurationAudit`) jest **arytmetyką przedziałów dat**, a nie przebiegiem po
     * kalendarzu: pyta o granice okresów, w których nikt nie nocuje, i budowanie dla nich
     * `FishingDay` byłoby pracą bez odbiorcy.
     */
    public function coversDay(CarbonImmutable $day): bool
    {
        if ($this->is_suspended) {
            return false;
        }

        return $this->withinDateRange($day->toDateString());
    }

    /**
     * Czy ta DOPŁATA należy się w tej dobie przy tej obsadzie.
     *
     * ⚠️ Sprawdzane osobno dla KAŻDEJ doby pobytu (K3/P2), nie „całe albo wcale": pobyt śr–pt
     * przy dopłacie „czw–nd" dostaje ją za czwartek i piątek, a nie za środę.
     *
     * ⚠️ `anglers_count` porównuje się przez RÓWNOŚĆ z faktyczną obsadą z zapytania — nigdy
     * z `positions.max_anglers`, która jest pojemnością stanowiska i kusi wyłącznie nazwą.
     * ⚠️ Obsadę liczą SAMI ŁOWIĄCY: osoba towarzysząca jej nie podnosi, więc dopłata za
     * wyłączność stanowiska nie znika przez to, że wędkarz przyjechał z kimś.
     *
     * `applies_to` NIE jest tu sprawdzane — ono nie decyduje, CZY dopłata wchodzi, tylko
     * PRZEZ ILU osób się ją mnoży. To robi wycena.
     */
    public function appliesToNight(FishingDay $night, int $anglersCount): bool
    {
        if ($this->is_suspended) {
            return false;
        }

        $weekdays = $this->weekdayNumbers();

        if ($weekdays !== [] && ! in_array((int) $night->startsOn->isoWeekday(), $weekdays, true)) {
            return false;
        }

        if (! $this->withinDateRange($night->startsOn->toDateString())) {
            return false;
        }

        return $this->anglers_count === null || (int) $this->anglers_count === $anglersCount;
    }

    /**
     * Ilu uczestników obciąża ta dopłata przy takim składzie.
     *
     * ⚠️ Domyślne `angler` (gdy kolumna jest pusta) jest celowe i NIE jest „bezpiecznym
     * fallbackiem": zliczenie wszystkich obciążyłoby DARMOWĄ osobę towarzyszącą, czyli
     * wprowadziłoby pomyłkę najtrudniejszą do zauważenia w całym cenniku.
     */
    public function chargeableHeadcount(int $anglersCount, int $companionsCount): int
    {
        return match ($this->applies_to) {
            SurchargeAudience::Everyone => $anglersCount + $companionsCount,
            SurchargeAudience::Companion => $companionsCount,
            default => $anglersCount,
        };
    }

    /**
     * Czy stawka jest BEZTERMINOWA, czyli jest „aktualnym cennikiem".
     *
     * ⚠️ Na tym rozróżnieniu stoi domykanie okresów: stawka bez daty końca to deklaracja
     * „tak jest teraz", stawka z datą końca to wstawka w istniejący cennik.
     * ⚠️ **Samo domykanie filtruje jednak w SQL-u** (`whereNull('last_day_on')`
     * w `PriceRulePeriods`), a nie tą metodą — bo pracuje na zbiorze, nie na wczytanym modelu.
     * Ta metoda jest predykatem dla kodu, który model już ma w ręku.
     */
    public function isOpenEnded(): bool
    {
        return $this->last_day_on === null;
    }

    /**
     * Dni ISO-8601 rozpoczęcia doby, na których dopłata obowiązuje.
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

    /**
     * Zakres dób reguły jako tekst: „2026-07-01–2026-08-31", „od 2026-01-01", „do 2026-12-31"
     * albo `null`, gdy reguła nie ma dat.
     *
     * ⚠️ Statyczna i na łańcuchach, bo pytają o to dwa miejsca o różnym kształcie danych:
     * nagłówek wiersza w formularzu cennika (stan formularza) i lista martwych stawek
     * w kalendarzu (model). Jeden dom zapisu zakresu zamiast dwóch literałów (zadanie 023).
     */
    public static function periodText(?string $firstDayOn, ?string $lastDayOn): ?string
    {
        if ($firstDayOn !== null && $lastDayOn !== null) {
            return $firstDayOn.'–'.$lastDayOn;
        }

        if ($firstDayOn !== null) {
            return __('from').' '.$firstDayOn;
        }

        if ($lastDayOn !== null) {
            return __('until').' '.$lastDayOn;
        }

        return null;
    }

    /**
     * Granice domknięte po obu stronach — reguła obowiązuje także w dobie `last_day_on`.
     */
    private function withinDateRange(string $day): bool
    {
        if ($this->first_day_on !== null && $day < $this->first_day_on->toDateString()) {
            return false;
        }

        return $this->last_day_on === null || $day <= $this->last_day_on->toDateString();
    }
}
