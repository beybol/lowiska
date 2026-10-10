<?php

namespace App\Mail;

/**
 * Paleta i typografia maili — JEDYNE miejsce z literałami kolorów wiadomości (zadanie 040).
 *
 * Wartości to tokeny „Głębi" z `resources/css/portal.css`, przepisane jako literały, bo poczta nie
 * czyta arkuszy ani zmiennych CSS: komponenty `x-mail.*` wstawiają je inline. Zmiana barwy portalu
 * → zmiana tutaj; komponent z własnym kolorem to drugi dom tej samej stałej.
 */
final class MailTheme
{
    public const BRAND = 'Fisherya';

    public const PAGE = '#F7FAFA';      // --color-surface

    public const CARD = '#FFFFFF';

    public const HEADER = '#0F4553';    // --color-b800

    public const HEADER_TEXT = '#FFFFFF';

    public const PRIMARY = '#1A7285';   // --color-b600

    public const PRIMARY_TEXT = '#FFFFFF';

    public const ACCENT = '#E8963C';    // --color-a500

    public const INK = '#0F1518';       // --color-ink

    public const INK2 = '#33454A';      // --color-ink2

    public const MUTED = '#5B7076';     // --color-muted

    public const LINE = '#E1E9EA';      // --color-line

    public const INFO_BG = '#F1FAFB';   // --color-b50

    public const INFO_BORDER = '#BFE7EE'; // --color-b200

    public const WARN = '#B4740B';      // --color-warn

    public const WARN_BG = '#FDF3E0';   // --color-warn-bg

    public const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    /** Szerokość treści — bezpieczna dla klientów pocztowych. */
    public const WIDTH = 600;

    /**
     * Logo z adresu aplikacji, nie z żądania — mail czytany jest poza aplikacją, a lokalne `localhost`
     * nie wyświetli się u odbiorcy. PNG, nie SVG: Gmail i Outlook nie pokazują SVG.
     */
    public static function logoUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/icon-192.png';
    }
}
