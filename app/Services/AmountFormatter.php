<?php

namespace App\Services;

use Illuminate\Support\Number;

/**
 * Kwota pieniężna jako tekst dla operatora — jedyny dom formatu (zadanie 023).
 *
 * ⚠️ Wcześniej komórka kalendarza formatowała inline w widoku, a lista martwych stawek
 * wypisywała surowe `90.00`. Drugi literał formatu to defekt (`CLAUDE.md`), więc każdy
 * nowy ekran pokazujący kwotę idzie tędy.
 *
 * ⚠️ **Waluta jest OPCJONALNA** i o jej obecności decyduje wołający: siatka kalendarza pokazuje
 * setki kwot w wąskich komórkach i waluta byłaby tam szumem; lista martwych stawek pokazuje
 * po jednej kwocie na wiersz i tam waluta pomaga.
 */
final class AmountFormatter
{
    public static function cents(int $cents, ?string $currency = null): string
    {
        $text = number_format($cents / 100, 2, ',', ' ');

        return filled($currency) ? $text.' '.$currency : $text;
    }

    /**
     * Kwota dla WĘDKARZA w portalu (zadanie 032) — w konwencji języka strony („70 zł", „PLN 70"),
     * bez groszy przy kwocie pełnej. Waluta to kod łowiska (`currencies.name`, np. `PLN`); bez niej
     * sama liczba — nie zgadujemy waluty za łowisko.
     */
    public static function forVisitor(int $cents, ?string $currency = null): string
    {
        $amount = $cents / 100;
        $precision = $cents % 100 === 0 ? 0 : 2;
        $locale = app()->getLocale();

        if (filled($currency)) {
            $formatted = Number::currency($amount, in: (string) $currency, locale: $locale, precision: $precision);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return (string) Number::format($amount, precision: $precision, locale: $locale);
    }
}
