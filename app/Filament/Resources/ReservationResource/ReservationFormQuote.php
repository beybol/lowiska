<?php

namespace App\Filament\Resources\ReservationResource;

use App\Enums\SaleUnavailabilityReason;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\AmountFormatter;
use App\Services\StayOffer;
use App\Services\StayOfferVerdict;
use App\Services\StayPriceExplanation;
use App\Services\StayPricing;
use App\Services\StayServicesQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Podgląd oferty w formularzach rezerwacji panelu (zadanie 047, B1/B2/B6) — krótkim w kalendarzu,
 * pełnym C, szybkiej i pełnej edycji. Składa odpowiedzi warstwy oferty (`StayOffer`) i rozbicie
 * (`StayPriceExplanation`); niczego nie liczy sam.
 *
 * - **Odmowa oferty** (`refused()`) — formularz pokazuje wtedy listę naruszonych reguł i dopiero wtedy
 *   pole powodu wyjątku (B1). Przy odmowie cena pochodzi wprost z cennika, jak w ścieżce zapisu operatora.
 * - **Usługa niedostępna** (`needsServiceException()`) — nieoferowana albo bez cechy: wyjątek z powodem.
 *   Brak egzemplarzy (`stockProblem()`) wyjątku nie ma — tylko ostrzeżenie przed odmową zapisu.
 *
 * ⚠️ Przy edycji własne sloty rezerwacji nie są zajętością: podgląd pomija je tak samo, jak ścieżka zapisu
 * zwalnia je przed sprawdzeniem (`ReservationBooking::write()`).
 */
final readonly class ReservationFormQuote
{
    /**
     * @param  list<string>  $refusalLines
     * @param  list<string>  $serviceWarnings
     */
    private function __construct(
        public StayOfferVerdict $verdict,
        public ?StayPriceExplanation $explanation,
        public StayServicesQuote $services,
        public array $refusalLines,
        public array $serviceWarnings,
        private bool $serviceException,
        private bool $stock,
        private ?string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $state  `position_id`, `starts_on`, `nights`, `anglers`, `companions`, `services`
     * @param  Position|null  $loaded  stanowisko już wczytane przez wołającego (siatka kalendarza, ze slotami od początku okna) —
     *                                 użyte tylko, gdy to TO stanowisko i TO łowisko; inaczej odczyt z bazy jak zawsze
     */
    public static function for(Fishery $fishery, array $state, ?Reservation $existing = null, ?Position $loaded = null): ?self
    {
        $position = match (true) {
            ! is_numeric($state['position_id'] ?? null) => null,
            $loaded !== null && (int) $loaded->getKey() === (int) $state['position_id'] && (int) $loaded->fishery_id === (int) $fishery->getKey() => $loaded,
            default => Position::query()->where('fishery_id', $fishery->getKey())->whereKey((int) $state['position_id'])->first(),
        };
        $startsOn = self::date($state['starts_on'] ?? null);
        $nights = (int) ($state['nights'] ?? 0);
        $anglers = (int) ($state['anglers'] ?? 0);
        $companions = max(0, (int) ($state['companions'] ?? 0));

        if ($position === null || $startsOn === null || $nights < 1 || $nights > 365 || $anglers < 1 || $anglers > 50 || $companions > 50) {
            return null;
        }

        $position->setRelation('fishery', $fishery);

        if ($existing !== null) {
            $position->setRelation('reservationSlots', $position->reservationSlots()->where('reservation_id', '!=', $existing->getKey())->get());
        }

        try {
            $offer = new StayOffer($position);
            $verdict = $offer->offer($startsOn, $nights, $anglers, $companions);
            $services = $offer->services($startsOn, $nights, ReservationFormSubmission::services((array) ($state['services'] ?? [])), $existing?->getKey());
            $breakdown = $verdict->available
                ? $verdict->breakdown
                : (new StayPricing($position))->breakdown($startsOn, $nights, $anglers, $companions);
        } catch (Throwable) {
            return null;
        }

        $refusal = [];

        if (! $verdict->available) {
            $reason = $verdict->reason ?? SaleUnavailabilityReason::NoPriceDefined;
            // Operator dostaje wskazanie progu z ustawień — sama etykieta odmowy nie mówi, ile wolno.
            $refusal[] = $reason === SaleUnavailabilityReason::StayTooLong && $fishery->max_nights !== null
                ? trans_choice('The reservation is longer than :count day set as the maximum reservation length.|The reservation is longer than :count days set as the maximum reservation length.', (int) $fishery->max_nights, ['count' => (int) $fishery->max_nights])
                : (string) $reason->label();

            if ($verdict->sellability?->bundleFirstDay !== null && $verdict->sellability->bundleLastDay !== null) {
                $refusal[] = __('The whole bundle runs :from–:to.', [
                    'from' => $verdict->sellability->bundleFirstDay->format('d.m'),
                    'to' => $verdict->sellability->bundleLastDay->format('d.m'),
                ]);
            }
        }

        $warnings = [];
        $serviceException = false;
        $stock = false;

        foreach ($services->chosen() as $line) {
            if ($line->warnings() !== []) {
                $warnings[] = $line->service->name.': '.implode('; ', $line->warnings());
            }

            $serviceException = $serviceException || ! $line->isAvailable();
            $stock = $stock || $line->exceedsStock();
        }

        foreach ($services->unknownServiceIds as $id) {
            $warnings[] = __('Service #:id is not available in this fishery.', ['id' => $id]);
        }

        return new self(
            $verdict,
            $breakdown !== null && $breakdown->isPriced() ? StayPriceExplanation::of($breakdown, $services) : null,
            $services,
            $refusal,
            $warnings,
            $serviceException,
            $stock,
            $fishery->currency?->name,
        );
    }

    /**
     * Oferta odmówiła z powodu, który operator może przekroczyć wyjątkiem z powodem (B1) — reguła sprzedaży,
     * blokada, wycofanie. Zajętość i brak ceny tu nie należą: pierwszej nie omija nikt, drugą zastępuje
     * cena uzgodniona (`ReservationBooking::write()`).
     */
    public function refused(): bool
    {
        return ! $this->verdict->available && $this->verdict->sellability !== null && ! $this->occupied();
    }

    /** Termin zajęty przez inną rezerwację — twarda odmowa, bez wyjątku (ADR-025). */
    public function occupied(): bool
    {
        return $this->verdict->reason === SaleUnavailabilityReason::Occupied;
    }

    public function needsServiceException(): bool
    {
        return $this->serviceException;
    }

    public function stockProblem(): bool
    {
        return $this->stock;
    }

    /** Suma do pokazania — `null`, gdy cennik nie wycenia pobytu. */
    public function totalLabel(): ?string
    {
        return $this->explanation === null ? null : AmountFormatter::cents($this->explanation->totalInCents(), $this->currency);
    }

    /**
     * Cena z rozbiciem, naruszone reguły i ostrzeżenia o usługach — HTML dla komponentu `Text`.
     */
    public function html(): HtmlString
    {
        $parts = [];

        if ($this->explanation !== null) {
            $rows = '';

            foreach ($this->explanation->stayLines as $line) {
                $rows .= self::row(e($line['label']), e(AmountFormatter::cents($line['cents'], $this->currency)));
            }

            if ($this->explanation->discountInCents > 0) {
                $rows .= self::row(e(__('Presale discount')), '−'.e(AmountFormatter::cents($this->explanation->discountInCents, $this->currency)));
            }

            foreach ($this->explanation->serviceLines as $line) {
                $rows .= self::row(e($line['label']), e(AmountFormatter::cents($line['cents'], $this->currency)));
            }

            $rows .= '<div class="flex justify-between gap-3 border-t border-gray-500/30 pt-1 font-semibold"><span>'.e(__('Total')).'</span><span data-quote-total>'
                .e((string) $this->totalLabel()).'</span></div>';

            $parts[] = '<div class="flex flex-col gap-0.5 text-sm" data-quote-breakdown>'.$rows.'</div>';
        } else {
            $parts[] = '<div class="text-sm">'.e(__('The price list does not price this stay — set an agreed price.')).'</div>';
        }

        if ($this->occupied()) {
            $parts[] = '<div class="rounded-lg border border-red-500/50 bg-red-500/10 p-2 text-sm" data-quote-occupied><strong>'
                .e(SaleUnavailabilityReason::Occupied->label()).'</strong> '.e(__('Pick other nights or another position.')).'</div>';
        } elseif ($this->refused()) {
            $parts[] = '<div class="rounded-lg border border-amber-500/50 bg-amber-500/10 p-2 text-sm" data-quote-refusal><strong>'
                .e(__('Outside the sale rules')).'</strong><ul class="list-disc ps-5">'
                .implode('', array_map(fn (string $line): string => '<li>'.e($line).'</li>', $this->refusalLines))
                .'</ul>'.e(__('You can still book it — give a reason; it is recorded as an exception.')).'</div>';
        }

        if ($this->serviceWarnings !== []) {
            $parts[] = '<div class="rounded-lg border border-amber-500/50 bg-amber-500/10 p-2 text-sm" data-quote-services><strong>'
                .e(__('Unavailable services')).'</strong><ul class="list-disc ps-5">'
                .implode('', array_map(fn (string $line): string => '<li>'.e($line).'</li>', $this->serviceWarnings))
                .'</ul>'
                .($this->stock ? e(__('Not enough units left — this can not be saved; lower the quantity or pick other nights.')).' ' : '')
                .($this->serviceException ? e(__('A service unavailable on this position can be added as an exception with a reason.')) : '')
                .'</div>';
        }

        return new HtmlString(implode('', $parts));
    }

    private static function row(string $label, string $amount): string
    {
        return '<div class="flex justify-between gap-3"><span>'.$label.'</span><span>'.$amount.'</span></div>';
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10))?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
