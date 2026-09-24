<?php

namespace App\Enums;

/**
 * Jednostka rozliczenia usługi dodatkowej — pole `additional_services.billing_unit` (zadanie 020).
 *
 * ⚠️ **Dwie wartości i obie mnożą się przez liczbę egzemplarzy** wybraną przez wędkarza.
 * „Za sztukę" to jedna z nich razy liczba, a „za wejście" nie da się sprzedać z góry, bo
 * wędkarz nie zna liczby wejść — taka opłata zostaje na miejscu (`cennik.md` §6).
 *
 * Rachunek jest regułą jednostki zapisaną w `cennik.md`; kod liczący kwotę usługi dla pobytu
 * powstaje razem z modułem zakładania rezerwacji, jego pierwszym odbiorcą.
 */
enum ServiceBillingUnit: string
{
    /** Cena × liczba dób pobytu × liczba egzemplarzy — łódka, hamak, postawienie przyczepy. */
    case PerNight = 'per_night';

    /** Cena × liczba egzemplarzy — pellet, lód, drewno. */
    case PerStay = 'per_stay';

    public function label(): string
    {
        return match ($this) {
            self::PerNight => __('Per night'),
            self::PerStay => __('Per stay'),
        };
    }

    /** Końcówka ceny w tabeli i w kalendarzu: „20,00 zł / doba". */
    public function priceSuffix(): string
    {
        return match ($this) {
            self::PerNight => __('night'),
            self::PerStay => __('stay'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
