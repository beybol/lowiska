<?php

namespace App\Enums;

/**
 * Komu naliczana jest dopłata — pole `price_rules.applies_to`, wyłącznie dla `surcharge`.
 *
 * ⚠️ **To NIE jest powrót wycofanej osi `participant_role`** (ADR-014, sekcja „Aktualizacja").
 * Tamta odpowiadała na pytanie „**która stawka** obowiązuje tego uczestnika" i konkurowała
 * z innymi regułami — stąd priorytety, remisy i pułapka „stawka towarzyszącej musi mieć
 * najwyższy priorytet". Ta odpowiada na „**komu** nalicza się ta dopłata" i z niczym nie
 * konkuruje: najgorsze, co może zrobić, to dodać albo nie dodać znaną kwotę.
 *
 * ⚠️ Dlatego osobny enum, a nie rozszerzony `ParticipantRole`: „każdy" nie jest rolą
 * uczestnika i nie ma czego etykietować w rozbiciu wyceny.
 *
 * ⚠️ **Domyślną wartością w formularzu jest `Angler`, nie `Everyone`.** Osoba towarzysząca
 * jest u obu znanych łowisk darmowa, więc dopłata naliczona „każdemu" bez zastanowienia
 * obciążyłaby kogoś, kto nie płaci nic — w Łopiennie 110,00 zł zamiast 90,00 zł. Model ma się
 * mylić w stronę tańszą i mniej zaskakującą.
 */
enum SurchargeAudience: string
{
    /** Dopłata mnoży się przez liczbę osób łowiących — wariant domyślny. */
    case Angler = 'angler';

    /** Dopłata mnoży się przez wszystkich uczestników pobytu. */
    case Everyone = 'everyone';

    /** Dopłata mnoży się przez same osoby towarzyszące. */
    case Companion = 'companion';

    public function label(): string
    {
        return match ($this) {
            self::Angler => __('For the angler'),
            self::Everyone => __('For everyone'),
            self::Companion => __('For the companion'),
        };
    }

    /**
     * Warianty do `Select` — w kolejności od najczęstszego.
     *
     * ⚠️ `Angler` stoi pierwszy celowo: to on jest domyślny i to on odpowiada obu realnym
     * cennikom, a kolejność listy jest pierwszą podpowiedzią, jaką dostaje operator.
     *
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
