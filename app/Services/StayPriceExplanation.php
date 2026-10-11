<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\PriceRuleKind;
use App\Enums\ServiceBillingUnit;

/**
 * Rozbicie ceny pobytu do pokazania człowiekowi — JEDNO miejsce składania (zadanie 047, B2).
 *
 * Z rozbicia warstwy oferty (`StayPriceBreakdown`) i wyceny usług (`StayServicesQuote`) składa linie:
 * stawka × osoby × doby, osoba towarzysząca (tylko płatna), dopłaty pod nazwami z cennika, obniżkę
 * przedsprzedażową, usługi według jednostki × liczba i sumę. Korzystają z niego dymek kalendarza portalu
 * (`PortalCalendar`) i oba formularze rezerwacji panelu.
 *
 * ⚠️ Kwoty w groszach — formatowanie należy do widoku (portal i panel formatują inaczej). Klasa niczego
 * nie liczy od nowa: sumy pochodzą z rozbicia i wyceny, tu się je tylko grupuje. Warstwa oferty nie
 * przejmuje prezentacji (granica ADR-015).
 */
final readonly class StayPriceExplanation
{
    /**
     * @param  list<array{label: string, cents: int}>  $stayLines
     * @param  list<array{label: string, cents: int}>  $serviceLines
     */
    private function __construct(
        public array $stayLines,
        public int $discountInCents,
        public int $stayTotalInCents,
        public array $serviceLines,
        public int $servicesTotalInCents,
    ) {}

    public static function of(StayPriceBreakdown $breakdown, ?StayServicesQuote $services = null): self
    {
        $groups = [];

        foreach ($breakdown->items() as $item) {
            // Osoba towarzysząca bezpłatna nie jest pozycją rachunku.
            if ($item->role === ParticipantRole::Companion && $item->amountInCents() === 0) {
                continue;
            }

            $label = match (true) {
                $item->kind === PriceRuleKind::Surcharge => (string) ($item->label ?: __('Surcharge')),
                $item->role === ParticipantRole::Companion => __('Companion'),
                default => __('Angler'),
            };

            $key = $label.'|'.$item->amountPerPersonInCents;
            $groups[$key]['label'] = $label;
            $groups[$key]['people'] = max($groups[$key]['people'] ?? 0, $item->people);
            $groups[$key]['nights'] = ($groups[$key]['nights'] ?? 0) + 1;
            $groups[$key]['total'] = ($groups[$key]['total'] ?? 0) + $item->amountInCents();
        }

        $stayLines = array_map(fn (array $group): array => [
            'label' => $group['label'].($group['people'] > 1 ? ' × '.$group['people'] : '').' × '.self::nights($group['nights']),
            'cents' => (int) $group['total'],
        ], array_values($groups));

        $serviceLines = [];

        foreach ($services?->chosen() ?? [] as $line) {
            $label = $line->service->name.($line->quantity > 1 ? ' × '.$line->quantity : '');

            if ($line->unit === ServiceBillingUnit::PerNight) {
                $label .= ' × '.self::nights($line->nights);
            }

            $serviceLines[] = ['label' => $label, 'cents' => $line->amountInCents()];
        }

        return new self(
            $stayLines,
            $breakdown->discountInCents(),
            $breakdown->totalInCents(),
            $serviceLines,
            $services?->totalInCents() ?? 0,
        );
    }

    public function totalInCents(): int
    {
        return $this->stayTotalInCents + $this->servicesTotalInCents;
    }

    private static function nights(int $count): string
    {
        return trans_choice(':count night|:count nights', $count, ['count' => $count]);
    }
}
