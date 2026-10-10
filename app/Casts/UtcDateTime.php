<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Moment zapisany w bazie w UTC i czytany jako UTC — niezależnie od `config('app.timezone')`.
 *
 * ⚠️ Strefą aplikacji jest `Europe/Warsaw`, a wbudowany rzut `datetime` formatuje moment w strefie
 * obiektu przy zapisie i interpretuje odczyt w strefie aplikacji. Moment z innej strefy wraca
 * wtedy przesunięty o różnicę stref. Rezerwacje (zadanie 039, ADR-025) trzymają czas w UTC,
 * więc ich kolumny momentów idą przez ten rzut: zapis zawsze `->utc()`, odczyt zawsze w UTC.
 * Prezentację w strefie łowiska robi wołający (`Fishery::timezoneName()`).
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat(self::FORMAT, (string) $value, 'UTC') ?: null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);

        return $moment->utc()->format(self::FORMAT);
    }
}
