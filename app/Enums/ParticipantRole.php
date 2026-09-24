<?php

namespace App\Enums;

/**
 * Rola uczestnika pobytu — parametr zapytania o cenę i etykieta pozycji w rozbiciu.
 *
 * ⚠️ Uczestnik jest PARAMETREM ZAPYTANIA, nie rekordem w schemacie. Wycena pyta „ilu
 * łowiących, ile osób towarzyszących", a nie „kto konkretnie" — model rezerwacji
 * i uczestników powstanie dopiero razem z transakcją (G1, poza tą iteracją).
 *
 * ⚠️ **Rola NIE jest już osią warunku cenowego** (ADR-014, sekcja „Aktualizacja"). Cena osoby
 * towarzyszącej to KOLUMNA `price_rules.amount_companion` na stawce, a nie konkurencyjna reguła;
 * komu nalicza się dopłatę, mówi osobny enum `SurchargeAudience`. Tutaj została wyłącznie
 * **etykieta roli w rozbiciu wyceny** — to, co widać przy pozycji „Łowiący" albo „Osoba
 * towarzysząca". Nie przywracaj jej do roli warunku: wróciłaby razem z priorytetami.
 */
enum ParticipantRole: string
{
    /** Osoba łowiąca — domyślny uczestnik pobytu. */
    case Angler = 'angler';

    /** Osoba towarzysząca, niełowiąca. */
    case Companion = 'companion';

    public function label(): string
    {
        return match ($this) {
            self::Angler => __('Angler'),
            self::Companion => __('Companion'),
        };
    }
}
