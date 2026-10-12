<?php

namespace App\Services;

use App\Enums\TermsExceptionKind;
use App\Models\Reservation;

/**
 * Pasek rezerwacji w siatce dni kalendarzowych (model połówek): pobyt zaczyna się w DRUGIEJ połowie dnia
 * przyjazdu i kończy w PIERWSZEJ połowie dnia wyjazdu. `column` — pierwszy dotknięty dzień okna, `span` —
 * liczba dotkniętych dni (z dniem wyjazdu). `startsBefore` — pobyt zaczął się przed oknem (◀, bez ukosu
 * na początku); `endsAfter` — dzień wyjazdu wypada po oknie (bez ukosu na końcu).
 * `kind`: `pending` · `confirmed` · `paid` · `in_progress` (kolor paska). `dimmed` — pasek nie pasuje
 * do wybranego filtra (licznik, status, „wymagają reakcji"); zostaje w siatce, bo doba i tak jest zajęta.
 */
final readonly class ReservationCalendarBar
{
    public function __construct(
        public Reservation $reservation,
        public int $column,
        public int $span,
        public bool $startsBefore,
        public bool $endsAfter,
        public string $kind,
        public bool $dimmed = false,
    ) {}

    /** Czy doba zaczynająca się w dniu o tym indeksie należy do pobytu (dzień wyjazdu już nie). */
    public function coversNight(int $index): bool
    {
        $lastNight = $this->column + $this->span - ($this->endsAfter ? 1 : 2);

        return $index >= $this->column && $index <= $lastNight;
    }

    public function label(): string
    {
        $contact = $this->reservation->angler?->effectiveContact() ?? [];

        return (string) ($contact['name'] ?? $this->reservation->terms->contact_snapshot['name'] ?? '#'.$this->reservation->getKey());
    }

    /** Liczba osób z zamrożonych warunków (łowiący + towarzyszący). */
    public function people(): int
    {
        $terms = $this->reservation->terms;

        return (int) ($terms->anglers ?? 1) + (int) ($terms->companions ?? 0);
    }

    /**
     * Wyjątki od dzisiejszej konfiguracji, które pasek oznacza (044): rezerwacja zostawiona przy blokadzie albo wycofaniu
     * stanowiska (decyzja „zostaw") i rezerwacja zapisana wbrew regułom sprzedaży. Pusta lista = bez oznaczenia.
     *
     * @return array<int, string>
     */
    public function exceptionLabels(): array
    {
        $terms = $this->reservation->terms;

        if ($terms === null) {
            return [];
        }

        return collect([TermsExceptionKind::SaleBlocked, TermsExceptionKind::SaleRule])
            ->filter(fn (TermsExceptionKind $kind): bool => $terms->hasException($kind))
            ->map(fn (TermsExceptionKind $kind): string => $kind->label())
            ->values()
            ->all();
    }

    /** Kwota z zamrożonych warunków — z usługami i ceną uzgodnioną (047, A3). */
    public function totalInCents(): ?int
    {
        return $this->reservation->terms?->total_cents;
    }
}
