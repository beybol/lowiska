<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Dzień kalendarzowy stanowiska w warstwie „Rezerwacje" — dwie połowy (model połówek):
 * `morningOccupied` — pierwsza połowa należy do doby zaczętej dzień wcześniej (ktoś wyjeżdża),
 * `occupied` — druga połowa, czyli doba zaczynająca się tego dnia (ktoś przyjeżdża albo trwa pobyt).
 *
 * Przyjazd można wskazać tylko w dniu z wolną drugą połową; dzień z zajętą drugą połową może być
 * dniem wyjazdu zaznaczenia. Przeszłość jest nieklikalna.
 *
 * `offer` — komórka warstwy „Oferta" dla doby od tego dnia (`SaleCalendar`, zadanie 047 A3): cena albo
 * „zacznij …" drobnym drukiem. Kalendarz rezerwacji nie liczy oferty sam.
 */
final readonly class ReservationCalendarCell
{
    public function __construct(
        public CarbonImmutable $day,
        public bool $past,
        public bool $occupied,
        public bool $blocked,
        public ?SaleCalendarCell $offer = null,
        public bool $morningOccupied = false,
    ) {}

    /** Dzień może być dniem przyjazdu — doba od tego dnia jest wolna. */
    public function isSelectable(): bool
    {
        return ! $this->past && ! $this->occupied;
    }

    /** Którakolwiek połowa wolna — komórka reaguje na klik (przyjazd albo wyjazd zaznaczenia). */
    public function isClickable(): bool
    {
        return ! $this->past && ! ($this->occupied && $this->morningOccupied);
    }
}
