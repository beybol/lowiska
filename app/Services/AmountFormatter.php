<?php

namespace App\Services;

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
}
