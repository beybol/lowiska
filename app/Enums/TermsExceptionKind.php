<?php

namespace App\Enums;

/**
 * Rodzaj jawnego wyjątku zapisanego w warunkach rezerwacji (ADR-026 pkt 7–8 i aktualizacja
 * z 10.10, ADR-027 pkt 7). Każdy wyjątek niesie powód, autora i chwilę.
 */
enum TermsExceptionKind: string
{
    /** Cena uzgodniona inna niż wyliczona (ADR-026 pkt 7). */
    case AgreedPrice = 'agreed_price';

    /** Rezerwacja wbrew odmowie sprzedaży — reguły pobytu, sezon, wycofane stanowisko. */
    case SaleRule = 'sale_rule';

    /** Rezerwacja na stanowisku z blokadą sprzedaży (ADR-027 pkt 7). */
    case SaleBlocked = 'sale_blocked';

    /** Zwrot inny niż z polityki zwrotu z chwili umowy (ADR-026, aktualizacja 10.10). */
    case Refund = 'refund';

    /**
     * Usługa nieoferowana na stanowisku albo bez wymaganej cechy (zadanie 047, ADR-026 aktualizacja 11.10).
     * ⚠️ Wyczerpany limit egzemplarzy wyjątku nie ma — to podwójna sprzedaż przedmiotu (ADR-025 pkt 7).
     */
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::AgreedPrice => __('Agreed price'),
            self::SaleRule => __('Booked against the sale rules'),
            self::SaleBlocked => __('Booked on a blocked position'),
            self::Refund => __('Refund other than the policy'),
            self::Service => __('Service added despite being unavailable'),
        };
    }
}
